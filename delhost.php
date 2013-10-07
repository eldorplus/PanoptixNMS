#!/usr/bin/env php
<?php

/**
 * PanoptixNMS
 *
 *   This file is part of PanoptixNMS.
 *
 * @package    panoptixnms
 * @subpackage cli
 * @author     Adam Armstrong <adama@memetic.org>
 * @copyright  (C) 2006 - 2012 Adam Armstrong
 *
 */

chdir(dirname($argv[0]));

include("includes/defaults.inc.php");
include("config.php");
include("includes/definitions.inc.php");
include("includes/functions.php");

# Remove a host and all related data from the system

if ($argv[1])
{
  $host = strtolower($argv[1]);
  $id = getidbyname($host);
  if ($id)
  {
    echo(delete_device($id));
    echo("Removed $host\n");
  } else {
    echo("Host doesn't exist!\n");
  }
} else {
  echo("Host Removal Tool\nUsage: ./delhost.php <hostname>\n");
}

?>
