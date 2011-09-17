<?php

class DRPack
{

//const DEBUG = TRUE;
const DEBUG = FALSE; 

const State_Init = 0x01;
const State_First = 0x02;
const State_Normal = 0x03;
const State_Repeat = 0x04;
const State_High = 0x05;
const State_EndOfData = 0x06;
const State_Error = 0xFF;
const State_Exit = 0x00;

const Action_Read = 0x01;
const Action_Process = 0x02;
const Action_Write = 0x03;
const Action_Other = 0x00;

const Max_Short_Repeat = 0xE;
const Max_Long_Repeat = 0xFF;

const Max_Short_Literal = 0xD;
const Max_Long_Literal = 0xFF;

public $state;
public $action;

public $last_state;
public $last_action;

public $repeat_counter;
public $high_counter;

public $input;
public $output;

public $high_buffer;
public $high_tmp;

public $reference_byte;
public $new_byte;

public $named_states;
public $named_actions;

public function __construct($input = STDIN, $output = STDOUT)
{

	$this->state = 0x01;
	$this->action = 0x00;

	$this->named_states = array(0x01 => 'Init', 0x02 => 'First', 0x03 => 'Normal', 0x04 => 'Repeat', 0x05 => 'High', 0x06 => 'EndOfData', 0xFF => 'Error', 0x00 => 'Exit');
	$this->named_actions = array(0x01 => 'Read', 0x02 => 'Process', 0x03 => 'Write', 0x00 => 'Other');

	$this->high_buffer = array();
	$this->high_tmp = NULL;

	if ($input)
	{
		$this->input = $input;
	}

	if ($output)
	{
		$this->output = $output;
	}
}

public function transition($new_state , $new_action = self::Action_Other)
{
	$this->last_state = $this->state;
	if ($new_state !== NULL)
	{
		$this->state = $new_state;
	}

	if ($new_action !== NULL)
	{
		$this->last_action = $this->action;
		$this->action = $new_action;
	}

	if (self::DEBUG)	echo sprintf("Transitioning from %s::%s to %s::%s\n", $this->named_states[$this->last_state], $this->named_actions[$this->last_action], $this->named_states[$this->state], $this->named_actions[$this->action]);


	return;
}

public function is_high($byte)
{
	return (($byte & 0xF0) == 0xF0);
}

public function is_low($byte)
{
	return (($byte & 0xF0) != 0xF0);
}

public function add_to_buffer($byte)
{
	$this->high_buffer[] = $byte;
}

public function pop_last_byte_from_buffer()
{
	return array_pop($this->high_buffer);
}

public function write_normal_byte($byte)
{
	$this->write($byte);
}

public function write_repeated_byte($byte, $count)
{
	if ($count <= self::Max_Short_Repeat)
	{
		$leader = 0xF0 | ($count);
		$this->write($leader);
		$this->write($byte);
	}
	elseif($count <= self::Max_Long_Repeat)
	{
		$this->write(0xFF);
		$this->write($count);
		$this->write($byte);
	}
	else
	{
		$this->write_repeated_byte($byte, self::Max_Long_Repeat);
		$count -= self::Max_Long_Repeat;
		$this->write_repeated_byte($byte, $count);
	}
}

public function write_literal_bytes($buffer)
{
	if (count($buffer) == 0)
	{
	}
	elseif (count($buffer) == 1)
	{
		$this->write(0xF0);
		$this->write($buffer[0]);
	}
	elseif (count($buffer) <= self::Max_Short_Literal)
	{
		$this->write(0xFF);
		$this->write(count($buffer));
		foreach ($buffer as $byte)
		{
			$this->write($byte);
		}
	}
	else
	{
		$this->write(0xFF);
		$this->write(0x0E);
		$this->write(count($buffer));
		foreach ($buffer as $byte)
		{
			$this->write($byte);
		}
	}
	// Note that this function does NOT currently handle the overflow condition!
}

public function compress()
{

	$this->transition(self::State_Init);

	//echo "Initial Status: {$this->named_states[$this->state]}::{$this->named_actions[$this->action]}" . PHP_EOL;

	while ($this->state != self::State_Exit)
	{
		$this->iterate();
		//echo "Current Status: {$this->named_states[$this->state]}::{$this->named_actions[$this->action]}" . PHP_EOL;
	}

	//echo "Leaving loop.." . PHP_EOL;
	//echo "Final Status: {$this->named_states[$this->state]}::{$this->named_actions[$this->action]}" . PHP_EOL;
}

public function read()
{
	$in = fgetc($this->input);
	if ($in !== FALSE)
	{
		$in = ord($in);
		if (self::DEBUG) echo sprintf("===READ=== << %02X = %s\n", $in, chr($in));
		return $in;
	}
	else
	{
	if (self::DEBUG) echo "===READ=== << EOF EOF EOF\n";
		return FALSE;
	}
}

public function write($data)
{
	if (!self::DEBUG) fwrite($this->output, chr($data));
	if (self::DEBUG) echo sprintf("---OUT--- >> %02X = %s\n", $data, chr($data));
}

public function iterate()
{

	switch ($this->state)
	{

		case self::State_Init: 
		{
			$this->repeat_counter = 0;
			$this->high_buffer = array();
			$this->reference_byte = NULL;
			$this->new_byte = NULL;

			$this->transition(self::State_First, self::Action_Read);

		}
		break;

		case self::State_First:
		{
			switch ($this->action)
			{
				case self::Action_Read:
				{ // First
					$this->reference_byte = $this->read();
					
					if ($this->reference_byte !== FALSE)
					{
						$this->transition(NULL, self::Action_Process);
						break;
					}
					$this->transition(self::State_EndOfData);
					break;
				}
				break;

				case self::Action_Process:
				{ // First
					if ($this->is_high($this->reference_byte))
					{
						$this->transition(self::State_High, self::Action_Read);
						break;
					}
					else
					{
						$this->transition(self::State_Normal, self::Action_Read);
						break;
					}
				}
				break;
			}

		}
		break;

		case self::State_Normal: 
		{

			switch ($this->action)
			{
				case self::Action_Read:
				{ // Normal
					$this->new_byte = $this->read();

					if ($this->new_byte !== FALSE)
					{
						$this->transition(NULL, self::Action_Process);
						break;
					}
					$this->transition(self::State_EndOfData);
					break;	// don't allow follow-on
				}
				break;

				case self::Action_Process:
				{ // Normal

					if ($this->new_byte === $this->reference_byte)
					{ // this byte is a repeat, go to repeat system
						$this->transition(self::State_Repeat, self::Action_Process);
						break;
					}

					if ($this->is_high($this->new_byte & 0xF0))
					{ // this byte has high nibble of 0xF, go to high system
						$this->transition(self::State_High, self::Action_Process);
						break;
					}

					// none of the above, write it out
					$this->transition(NULL, self::Action_Write);
					break;	// don't allow follow-on
				}
				break;

				case self::Action_Write:
				{ // Normal
					$this->write_normal_byte($this->reference_byte);
					$this->reference_byte = $this->new_byte;
					$this->transition(NULL, self::Action_Read);
					break;	// don't allow follow-on
				}
				break;
			}
			break;
		}
		break;

		case self::State_Repeat: 
		{
			switch ($this->action)
			{
				case self::Action_Read:
				{ // Repeat

					$this->new_byte = $this->read();

					if ($this->new_byte !== FALSE)
					{
						$this->transition(NULL, self::Action_Process);
						break;
					}
					$this->transition(self::State_EndOfData);
					break;	// don't allow follow-on
				}
				break;

				case self::Action_Process:
				{ // Repeat

					if ($this->new_byte === $this->reference_byte)
					{
						$this->repeat_counter++;

						// don't forget to check for threshold here

						$this->transition(NULL, self::Action_Read);
						break;
					}
					
					$this->transition(NULL, self::Action_Write);
					break;
				}
				break;

				case self::Action_Write:
				{ // Repeat
					$this->write_repeated_byte($this->reference_byte, $this->repeat_counter);
					$this->repeat_counter = 0;
					$this->reference_byte = $this->new_byte;
					
					if ($this->is_high($this->reference_byte))
					{
						$this->transition(self::State_High, self::Action_Read);
						break;
					}
					else
					{
						$this->transition(self::State_Normal, self::Action_Read);
						break;
					}
					break;
				}
				break;
			}
		}
		break;

		case self::State_High: 
		{
			switch ($this->action)
			{
				case self::Action_Read:
				{ // High
					$this->new_byte = $this->read();

					if ($this->new_byte !== FALSE)
					{
						$this->transition(NULL, self::Action_Process);
						break;
					}
					$this->transition(self::State_EndOfData);
					break;	// don't allow follow-on
				}
				break;

				case self::Action_Process:
				{ // High
					if (self::DEBUG) echo "Ref: {$this->reference_byte}, New: {$this->new_byte}, Buff:\n";
					if (self::DEBUG) print_r($this->high_buffer);
					if ($this->new_byte === end($this->high_buffer))
					{
						if (self::DEBUG) echo "new matches end of buff\n";
						$this->high_tmp = array_pop($this->high_buffer);
						if (self::DEBUG) echo "popped value is ({$this->high_tmp})\n";
						if (self::DEBUG) print_r($this->high_buffer);
						$this->transition(NULL, self::Action_Write);
						break;
					}
					elseif ($this->is_low($this->new_byte))
					{
						if (self::DEBUG) echo "new is low\n";
						$this->high_tmp = NULL;
						$this->transition(NULL, self::Action_Write);
						break;
					}
					else
					{
						if (self::DEBUG) echo "new is high\n";
						$this->add_to_buffer($this->new_byte);
						if (self::DEBUG) print_r($this->high_buffer);
						$this->transition(NULL, self::Action_Read);
						break;
					}

				}
				break;

				case self::Action_Write:
				{ // High
					if ($this->high_tmp !== NULL)
					{
						if (self::DEBUG) echo "here\n";
						$this->write_normal_byte($this->reference_byte);
						$this->write_literal_bytes($this->high_buffer);
						$this->high_buffer = array();
						$this->reference_byte = $this->high_tmp;
						$this->high_tmp = NULL;
						$this->transition(self::State_Repeat, self::Action_Process);
						break;
					}
					else
					{
						if (self::DEBUG) echo "there\n";
						$this->write_normal_byte($this->reference_byte);
						$this->write_literal_bytes($this->high_buffer);
						$this->high_buffer = array();
						$this->reference_byte = $this->new_byte;
						$this->transition(self::State_Normal, self::Action_Read);
						break;
					}
				}
				break;
			}
		}
		break;

		case self::State_EndOfData: 
		{
			// EOF for First
			// Do nothing

			// EOF for Normal
			if (($this->last_state == self::State_Normal))
			{
				$this->write_normal_byte($this->reference_byte);
			}

			// EOF for Repeat	
			elseif ($this->last_state == self::State_Repeat)
			{
				$this->write_repeated_byte($this->reference_byte, $this->repeat_counter);
			}

			// EOF for High
			elseif ($this->last_state == self::State_High)
			{
				$this->write_normal_byte($this->reference_byte);
				$this->write_literal_bytes($this->high_buffer);
			}

			$this->transition(self::State_Exit);

			break;
		}

		case self::State_Error: 
		{
			echo "State: ERROR" . PHP_EOL;

			// Error code goes here

			break;
		}
		
	}

}
}
