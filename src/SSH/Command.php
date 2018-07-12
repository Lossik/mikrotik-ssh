<?php declare(strict_types=1);


namespace Lossik\Device\Mikrotik\SSH;


class Command extends \Lossik\Device\Communication\Command
{


	public function add(array $record)
	{
		return $this->command('%menu% add %arr%', $record);
	}


	public function command($com, array $arr = [])
	{
		$com = str_replace(['%menu%', '%arr%'], [$this->menu[0] === '/' ? $this->menu : '/' . $this->menu, $this->arrayToString($arr)], $com);
		return $this->connection->comm($com);
	}


	private function arrayToString(array $arr = [])
	{
		if (!$arr) {
			return '';
		}
		$result = [];
		foreach ($arr as $key => $value) {
			if (is_numeric($key)) {
				$value = strpos($value, ' ') === false ? $value : "\"$value\"";
				$result[] = $value;
			}
			else {
				if(strpos($value,'~') === 0){
					$value = str_replace('~','',$value);
					$value = strpos($value, ' ') === false ? $value : "\"$value\"";
					$result[] = $key . '~' . $value;
				}else{
					$value = strpos($value, ' ') === false ? $value : "\"$value\"";
					$result[] = $key . '=' . $value;
				}
			}
		}

		return implode(' ', $result);
	}


	public function update(array $where, array $record, $filterCallback = null, $onlyFirstItem = false)
	{
		// todo zvazit optimalizaci pres find => nelze pouzit filterCallback a onlyFirstItem a neni potreba get
		$items = $this->get($where, $filterCallback, $onlyFirstItem);
		$ids   = array_column($items, '.id');

		return $this->command('%menu% set ' . implode(',', $ids) . ' %arr%', $record);
	}


	public function get(array $where = [], $filterCallback = null, $onlyFirstItem = false)
	{
		if ($where) {
			array_unshift($where, 'where');
		}

		$read   = $this->command(':put [%menu% print as-value %arr%]', $where);
		$read   = str_replace(PHP_EOL, '', $read);
		$result = [];
		$startItemKey = null;

		foreach ($read ? explode(';', $read) : [] as $key_value) {
			$key_value = explode('=', $key_value);
			if (is_null($startItemKey)) {
				$startItemKey = $key_value[0];
			}
			if ($key_value[0] == $startItemKey) {
				$current = &$result[];
			}
			if (count($key_value) == 2) {
				$current[$key_value[0]] = $key_value[1];
			}
			else {
				$current[] = $key_value[0];
			}
		}

		if ($filterCallback) {
			$result = array_filter($result, $filterCallback);
		}

		return $result;
	}


	public function del(array $where, $filterCallback = null, $onlyFirstItem = false)
	{
		// todo zvazit optimalizaci pres find => nelze pouzit filterCallback a onlyFirstItem a neni potreba get
		$items = $this->get($where, $filterCallback, $onlyFirstItem);
		$ids   = array_column($items, '.id');

		return $this->command('%menu% remove ' . implode(',', $ids));
	}


}