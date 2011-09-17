#!/usr/bin/env php
<?php

require_once 'DRPack.php';

if ($argc < 2) die('No input file specified'.PHP_EOL);

$input_file_handle = ($argv[1] == '-c') ? STDIN : fopen($argv[1], 'rb+');
$output_file_handle = STDOUT;

if ($input_file_handle) 
{
	
	$drp = new DRPack($input_file_handle, $output_file_handle);
	$drp->compress();

}
