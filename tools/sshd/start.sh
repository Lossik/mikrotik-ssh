#!/bin/bash
# OpenSSH server for the docker test group, run inside the php:X-cli container from the repository root:
#
#   bash tools/sshd/start.sh
#
# Installs openssh-server, creates two test users with random passwords (one logs in to a shell, the other to a command
# that never answers: the "silent" device), starts sshd on 127.0.0.1:2222 and waits until the port answers with an SSH
# banner. The connection settings are written to .cache/sshd/env (KEY=value lines, not
# committed); tests/Docker reads them from there.
#
# The docker group as a whole: bash tools/ci.sh --deps-only && bash tools/sshd/start.sh && vendor/bin/phpunit --group docker
set -euo pipefail

SSHD_HOST=127.0.0.1
SSHD_PORT=2222
SSHD_USER=lossik-test
SSHD_SILENT_USER=lossik-silent
ENV_DIR=.cache/sshd
ENV_FILE="$ENV_DIR/env"

step() {
	echo "=== sshd: $*"
}

step "install openssh-server"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq --no-install-recommends openssh-server > /dev/null

step "test user $SSHD_USER (random password)"
SSHD_PASS=$(php -r 'echo bin2hex(random_bytes(12));')
if ! id -u "$SSHD_USER" > /dev/null 2>&1; then
	useradd --create-home --shell /bin/bash "$SSHD_USER"
fi
echo "$SSHD_USER:$SSHD_PASS" | chpasswd

step "silent user $SSHD_SILENT_USER (random password, every command is sleep)"
SSHD_SILENT_PASS=$(php -r 'echo bin2hex(random_bytes(12));')
if ! id -u "$SSHD_SILENT_USER" > /dev/null 2>&1; then
	useradd --create-home --shell /bin/bash "$SSHD_SILENT_USER"
fi
echo "$SSHD_SILENT_USER:$SSHD_SILENT_PASS" | chpasswd
mkdir -p /etc/ssh/sshd_config.d
cat > /etc/ssh/sshd_config.d/lossik-silent.conf <<EOF
Match User $SSHD_SILENT_USER
	ForceCommand sleep 120
EOF

step "start sshd on $SSHD_HOST:$SSHD_PORT"
mkdir -p /run/sshd
ssh-keygen -A > /dev/null
# The distribution config is kept on purpose: it carries the "Subsystem sftp" line the SFTP tests need.
/usr/sbin/sshd -p "$SSHD_PORT" -o ListenAddress="$SSHD_HOST" -o PasswordAuthentication=yes

step "wait for the SSH banner"
BANNER=
for _ in $(seq 1 50); do
	if exec 3<> "/dev/tcp/$SSHD_HOST/$SSHD_PORT" 2> /dev/null; then
		read -r -t 2 BANNER <&3 || true
		exec 3<&- 3>&-
		if [[ "$BANNER" == SSH-* ]]; then
			break
		fi
	fi
	BANNER=
	sleep 0.2
done
if [ -z "$BANNER" ]; then
	echo "ERROR: sshd on $SSHD_HOST:$SSHD_PORT did not answer with an SSH banner" >&2
	exit 1
fi
echo "banner: ${BANNER%$'\r'}"

mkdir -p "$ENV_DIR"
umask 077
cat > "$ENV_FILE" <<EOF
SSHD_HOST=$SSHD_HOST
SSHD_PORT=$SSHD_PORT
SSHD_USER=$SSHD_USER
SSHD_PASS=$SSHD_PASS
SSHD_SILENT_USER=$SSHD_SILENT_USER
SSHD_SILENT_PASS=$SSHD_SILENT_PASS
EOF
step "ready, settings in $ENV_FILE"
