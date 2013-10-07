<?php

/* PanoptixNMS Network Management and Monitoring System
 * Copyright (C) 2006-2011, Observium Developers
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * See COPYING for more details.
 */

function discover_new_device($hostname)
{
  global $config;

  if ($config['autodiscovery']['xdp']) {
    if ( isDomainResolves($hostname . "." . $config['mydomain']) ) {
      $dst_host = $hostname . "." . $config['mydomain'];
    } else {
      $dst_host = $hostname;
    }
    $ip = gethostbyname($dst_host);

    if ( match_network($config['nets'], $ip) )
    {
      $remote_device_id = addHost ($dst_host);
      if ($remote_device_id) {
        $remote_device = device_by_id_cache($remote_device_id, 1);
        echo("+[".$remote_device['hostname']."(".$remote_device['device_id'].")]");
        discover_device($remote_device);
        $remote_device = device_by_id_cache($remote_device_id, 1);
        return $remote_device_id;
      }
    }
  } else {
    return FALSE;
  }
}

function discover_device($device, $options = NULL)
{
  global $config, $valid;

  $valid = array(); // Reset $valid array

  $attribs = get_dev_attribs($device['device_id']);

  $device_start = utime();  // Start counting device poll time

  echo($device['hostname'] . " ".$device['device_id']." ".$device['os']." ");

  if ($device['os'] == 'generic') // verify if OS has changed from generic
  {
      $device['os']= getHostOS($device);
      if ($device['os'] != 'generic')
      {
          echo "\nDevice os was updated to ".$device['os']."!";
          dbUpdate(array('os' => $device['os']), 'devices', '`device_id` = ?', array($device['device_id']));
      }
  }

  if ($config['os'][$device['os']]['group'])
  {
    $device['os_group'] = $config['os'][$device['os']]['group'];
    echo(" (".$device['os_group'].")");
  }

  echo("\n");

  // If we've specified a module, use that, else walk the modules array
  if ($options['m'])
  {
    if (is_file("includes/discovery/".$options['m'].".inc.php"))
    {
      include("includes/discovery/".$options['m'].".inc.php");
    }
  } else {
    foreach ($config['discovery_modules'] as $module => $module_status)
    {
      if ($attribs['discover_'.$module] || ( $module_status && !isset($attribs['discover_'.$module])))
      {
        include('includes/discovery/'.$module.'.inc.php');
      } elseif (isset($attribs['discover_'.$module]) && $attribs['discover_'.$module] == "0") {
        echo("Module [ $module ] disabled on host.\n");
      } else {
        echo("Module [ $module ] disabled globally.\n");
      }
    }
  }

  // Set type to a predefined type for the OS if it's not already set

  if ($device['type'] == "unknown" || $device['type'] == "")
  {
    if ($config['os'][$device['os']]['type'])
    {
      $device['type'] = $config['os'][$device['os']]['type'];
    }
  }

  $device_end = utime(); $device_run = $device_end - $device_start; $device_time = substr($device_run, 0, 5);

  dbUpdate(array('last_discovered' => array('NOW()'), 'type' => $device['type'], 'last_discovered_timetaken' => $device_time), 'devices', '`device_id` = ?', array($device['device_id']));

  echo("Discovered in $device_time seconds\n");

  global $discovered_devices;

  echo("\n"); $discovered_devices++;
}

// Discover sensors
function discover_sensor(&$valid, $class, $device, $oid, $index, $type, $descr, $divisor = '1', $multiplier = '1', $low_limit = NULL, $low_warn_limit = NULL, $warn_limit = NULL, $high_limit = NULL, $current = NULL, $poller_type = 'snmp', $entPhysicalIndex = NULL, $entPhysicalIndex_measured = NULL)
{
  global $config, $debug;

  if ($debug) { echo("Discover sensor: $oid, $index, $type, $descr, $poller_type, $precision, $entPhysicalIndex\n"); }

  if (is_null($low_warn_limit) || !is_null($warn_limit))
  {
    // Warn limits only make sense when we have both a high and a low limit
    $low_warn_limit = NULL;
    $warn_limit = NULL;
  }
  elseif ($low_warn_limit > $warn_limit)
  {
    // Fix high/low thresholds (i.e. on negative numbers)
    list($warn_limit, $low_warn_limit) = array($low_warn_limit, $warn_limit);
  }

  if (dbFetchCell("SELECT COUNT(sensor_id) FROM `sensors` WHERE `poller_type`= ? AND `sensor_class` = ? AND `device_id` = ? AND sensor_type = ? AND `sensor_index` = ?", array($poller_type, $class, $device['device_id'], $type, $index)) == '0')
  {
    if (!$high_limit) { $high_limit = sensor_limit($class, $current); }
    if (!$low_limit)  { $low_limit  = sensor_low_limit($class, $current); }

    if ($low_limit > $high_limit)
    {
      // Fix high/low thresholds (i.e. on negative numbers)
      list($high_limit, $low_limit) = array($low_limit, $high_limit);
    }

    $insert = array('poller_type' => $poller_type, 'sensor_class' => $class, 'device_id' => $device['device_id'], 'sensor_oid' => $oid, 'sensor_index' => $index, 'sensor_type' => $type, 'sensor_descr' => $descr,
                    'sensor_divisor' => $divisor, 'sensor_multiplier' => $multiplier, 'sensor_limit' => $high_limit, 'sensor_limit_warn' => $warn_limit, 'sensor_limit_low' => $low_limit,
                    'sensor_limit_low_warn' => $low_warn_limit, 'sensor_current' => $current, 'entPhysicalIndex' => $entPhysicalIndex, 'entPhysicalIndex_measured' => $entPhysicalIndex_measured );

    $inserted = dbInsert($insert, 'sensors');

    if ($debug) { echo("( $inserted inserted )\n"); }
    echo("+");
    log_event("Sensor Added: ".mres($class)." ".mres($type)." ". mres($index)." ".mres($descr), $device, 'sensor', mysql_insert_id());
  }
  else
  {
    $sensor_entry = dbFetchRow("SELECT * FROM `sensors` WHERE `sensor_class` = ? AND `device_id` = ? AND `sensor_type` = ? AND `sensor_index` = ?", array($class, $device['device_id'], $type, $index));

    if (!isset($high_limit))
    {
      if (!$sensor_entry['sensor_limit'])
      {
        // Calculate a reasonable limit
        $high_limit = sensor_limit($class, $current);
      } else {
        // Use existing limit
        $high_limit = $sensor_entry['sensor_limit'];
      }
    }

    if (!isset($low_limit))
    {
      if (!$sensor_entry['sensor_limit_low'])
      {
        // Calculate a reasonable limit
        $low_limit = sensor_low_limit($class, $current);
      } else {
        // Use existing limit
        $low_limit = $sensor_entry['sensor_limit_low'];
      }
    }

    // Fix high/low thresholds (i.e. on negative numbers)
    if ($low_limit > $high_limit)
    {
      list($high_limit, $low_limit) = array($low_limit, $high_limit);
    }

    if ($high_limit != $sensor_entry['sensor_limit'])
    {
      $update = array('sensor_limit' => ($high_limit == NULL ? array('NULL') : $high_limit));
      $updated = dbUpdate($update, 'sensors', '`sensor_id` = ?', array($sensor_entry['sensor_id']));
      if ($debug) { echo("( $updated updated )\n"); }
      echo("H");
      log_event("Sensor High Limit Updated: ".mres($class)." ".mres($type)." ". mres($index)." ".mres($descr)." (".$high_limit.")", $device, 'sensor', $sensor_id);
    }

    if ($sensor_entry['sensor_limit_low'] != $low_limit)
    {
      $update = array('sensor_limit_low' => ($low_limit == NULL ? array('NULL') : $low_limit));
      $updated = dbUpdate($update, 'sensors', '`sensor_id` = ?', array($sensor_entry['sensor_id']));
      if ($debug) { echo("( $updated updated )\n"); }
      echo("L");
      log_event("Sensor Low Limit Updated: ".mres($class)." ".mres($type)." ". mres($index)." ".mres($descr)." (".$low_limit.")", $device, 'sensor', $sensor_id);
    }

    if ($warn_limit != $sensor_entry['sensor_limit_warn'])
    {
      $update = array('sensor_limit_warn' => ($warn_limit == NULL ? array('NULL') : $warn_limit));
      $updated = dbUpdate($update, 'sensors', '`sensor_id` = ?', array($sensor_entry['sensor_id']));
      if ($debug) { echo("( $updated updated )\n"); }
      echo("WH");
      log_event("Sensor Warn High Limit Updated: ".mres($class)." ".mres($type)." ". mres($index)." ".mres($descr)." (".$warn_limit.")", $device, 'sensor', $sensor_id);
    }

    if ($sensor_entry['sensor_limit_low_warn'] != $low_warn_limit)
    {
      $update = array('sensor_limit_low_warn' => ($low_warn_limit == NULL ? array('NULL') : $low_warn_limit));
      $updated = dbUpdate($update, 'sensors', '`sensor_id` = ?', array($sensor_entry['sensor_id']));
      if ($debug) { echo("( $updated updated )\n"); }
      echo("WL");
      log_event("Sensor Warn Low Limit Updated: ".mres($class)." ".mres($type)." ". mres($index)." ".mres($descr)." (".$low_warn_limit.")", $device, 'sensor', $sensor_id);
    }

    if ($oid == $sensor_entry['sensor_oid'] && $descr == $sensor_entry['sensor_descr'] && $multiplier == $sensor_entry['sensor_multiplier'] && $divisor == $sensor_entry['sensor_divisor'] && $entPhysicalIndex_measured == $sensor_entry['entPhysicalIndex_measured'] && $entPhysicalIndex == $sensor_entry['entPhysicalIndex'])
    {
      echo(".");
    }
    else
    {
      $update = array('sensor_oid' => $oid, 'sensor_descr' => $descr, 'sensor_multiplier' => $multiplier, 'sensor_divisor' => $divisor,
                      'entPhysicalIndex' => $entPhysicalIndex, 'entPhysicalIndex_measured' => $entPhysicalIndex_measured);
      $updated = dbUpdate($update, 'sensors', '`sensor_id` = ?', array($sensor_entry['sensor_id']));
       echo("U");
      log_event("Sensor Updated: ".mres($class)." ".mres($type)." ". mres($index)." ".mres($descr), $device, 'sensor', $sensor_id);
      if ($debug) { echo("( $updated updated )\n"); }
    }
  }
  $valid[$class][$type][$index] = 1;
}

function sensor_low_limit($class, $current)
{
  $limit = NULL;

  switch($class)
  {
    case 'temperature':
      $limit = $current * 0.7;
      break;
    case 'voltage':
      if ($current < 0)
      {
        $limit = $current * (1 + (sgn($current) * 0.15));
      }
      else
      {
        $limit = $current * (1 - (sgn($current) * 0.15));
      }
      break;
    case 'humidity':
      $limit = "70";
      break;
    case 'frequency':
      $limit = $current * 0.95;
      break;
    case 'current':
      $limit = NULL;
      break;
    case 'fanspeed':
      $limit = $current * 0.80;
      break;
    case 'power':
      $limit = NULL;
      break;
  }
  return $limit;
}

function sensor_limit($class, $current)
{
  $limit = NULL;

  switch($class)
  {
    case 'temperature':
      $limit = $current * 1.60;
      break;
    case 'voltage':
      if ($current < 0)
      {
        $limit = $current * (1 - (sgn($current) * 0.15));
      }
      else
      {
        $limit = $current * (1 + (sgn($current) * 0.15));
      }
      break;
    case 'humidity':
      $limit = "70";
      break;
    case 'frequency':
      $limit = $current * 1.05;
      break;
    case 'current':
      $limit = $current * 1.50;
      break;
    case 'fanspeed':
      $limit = $current * 1.80;
      break;
    case 'power':
      $limit = $current * 1.50;
      break;
  }
  return $limit;
}

function check_valid_sensors($device, $class, $valid, $poller_type = 'snmp')
{
  $entries = dbFetchRows("SELECT * FROM sensors AS S, devices AS D WHERE S.sensor_class=? AND S.device_id = D.device_id AND D.device_id = ? AND S.poller_type = ?", array($class, $device['device_id'], $poller_type));

  if (count($entries))
  {
    foreach ($entries as $entry)
    {
      $index = $entry['sensor_index'];
      $type = $entry['sensor_type'];
      if ($debug) { echo($index . " -> " . $type . "\n"); }
      if (!$valid[$class][$type][$index])
      {
        echo("-");
        dbDelete('sensors', "`sensor_id` =  ?", array($entry['sensor_id']));
        log_event("Sensor Deleted: ".$entry['sensor_class']." ".$entry['sensor_type']." ". $entry['sensor_index']." ".$entry['sensor_descr'], $device, 'sensor', $sensor_id);
      }
      unset($oid); unset($type);
    }
  }
}

function discover_juniAtmVp(&$valid, $port_id, $vp_id, $vp_descr)
{
  global $config, $debug;

  if (dbFetchCell("SELECT COUNT(*) FROM `juniAtmVp` WHERE `port_id` = ? AND `vp_id` = ?", array($port_id, $vp_id)) == "0")
  {
     $inserted = dbInsert(array('port_id' => $port_id,'vp_id' => $vp_id,'vp_descr' => $vp_descr), 'juniAtmVp');
     if ($debug) { echo("( $inserted inserted )\n"); }
     #FIXME vv no $device!
     log_event("Juniper ATM VP Added: port ".mres($port_id)." vp ".mres($vp_id)." descr". mres($vp_descr), 'juniAtmVp', mysql_insert_id());
  }
  else
  {
    echo(".");
  }
  $valid[$port_id][$vp_id] = 1;
}

function discover_link($local_port_id, $protocol, $remote_port_id, $remote_hostname, $remote_port, $remote_platform, $remote_version)
{
  global $config, $debug, $link_exists;

  if (dbFetchCell("SELECT COUNT(*) FROM `links` WHERE `remote_hostname` = ? AND `local_port_id` = ? AND `protocol` = ? AND `remote_port` = ?",
                  array($remote_hostname, $local_port_id, $protocol, $remote_port)) == "0")
  {

    $inserted = dbInsert(array('local_port_id' => $local_port_id,'protocol' => $protocol,'remote_port_id' => $remote_port_id,'remote_hostname' => $remote_hostname,
             'remote_port' => $remote_port,'remote_platform' => $remote_platform,'remote_version' => $remote_version), 'links');

    echo("+"); if ($debug) { echo("( $inserted inserted )"); }
  }
  else
  {
    $data = dbFetchRow("SELECT * FROM `links` WHERE `remote_hostname` = ? AND `local_port_id` = ? AND `protocol` = ? AND `remote_port` = ?", array($remote_hostname, $local_port_id, $protocol, $remote_port));
    if ($data['remote_port_id'] == $remote_port_id && $data['remote_platform'] == $remote_platform && $remote_version == $remote_version)
    {
      echo(".");
    }
    else
    {
      $updated = dbUpdate(array('remote_port_id' => $remote_port_id, 'remote_platform' => $remote_platform, 'remote_version' => $remote_version), 'links', '`id` = ?', array($data['id']));
      echo("U"); if ($debug) { echo("( $updated updated )"); }
    }
  }
  $link_exists[$local_port_id][$remote_hostname][$remote_port] = 1;
}

function discover_storage(&$valid, $device, $index, $type, $mib, $descr, $size, $units, $used = NULL)
{
  global $config, $debug;

  if ($debug) { echo("$device, $index, $type, $mib, $descr, $units, $used, $size\n"); }
  if ($descr && $size > "0")
  {
    $storage = dbFetchRow("SELECT * FROM `storage` WHERE `storage_index` = ? AND `device_id` = ? AND `storage_mib` = ?", array($index, $device['device_id'], $mib));
    if ($storage === FALSE || !count($storage))
    {
      $insert = dbInsert(array('device_id' => $device['device_id'], 'storage_descr' => $descr, 'storage_index' => $index, 'storage_mib' => $mib, 'storage_type' => $type,
                               'storage_units' => $units, 'storage_size' => $size, 'storage_used' => $used), 'storage');
      if ($debug) { mysql_error(); }
      echo("+");
    }
    else
    {
      $updated = dbUpdate(array('storage_descr' => $descr, 'storage_type' => $type, 'storage_units' => $units, 'storage_size' => $size), 'storage', '`device_id` = ? AND `storage_index` = ? AND `storage_mib` = ?', array($device['device_id'], $index, $mib));
      if ($updated) { echo("U"); } else { echo("."); }
    }
    $valid[$mib][$index] = 1;
  }
}

function discover_processor(&$valid, $device, $oid, $index, $type, $descr, $precision = "1", $current = NULL, $entPhysicalIndex = NULL, $hrDeviceIndex = NULL)
{
  global $config, $debug;

  if ($debug) { echo("$device, $oid, $index, $type, $descr, $precision, $current, $entPhysicalIndex, $hrDeviceIndex\n"); }
  if ($descr)
  {
    $descr = trim(str_replace("\"", "", $descr));
    if (mysql_result(mysql_query("SELECT count(processor_id) FROM `processors` WHERE `processor_index` = '$index' AND `device_id` = '".$device['device_id']."' AND `processor_type` = '$type'"),0) == '0')
    {
      $query = "INSERT INTO processors (`entPhysicalIndex`, `hrDeviceIndex`, `device_id`, `processor_descr`, `processor_index`, `processor_oid`, `processor_usage`, `processor_type`, `processor_precision`)
                      values ('$entPhysicalIndex', '$hrDeviceIndex', '".$device['device_id']."', '$descr', '$index', '$oid', '$current', '$type','$precision')";
      mysql_query($query);
      if ($debug) { print $query . "\n"; }
      echo("+");
      log_event("Processor added: type ".mres($type)." index ".mres($index)." descr ". mres($descr), $device, 'processor', mysql_insert_id());
    }
    else
    {
      echo(".");
      $query = "UPDATE `processors` SET `processor_descr` = '".$descr."', `processor_oid` = '".$oid."', `processor_precision` = '".$precision."'
                      WHERE `device_id` = '".$device['device_id']."' AND `processor_index` = '".$index."' AND `processor_type` = '".$type."'";
      mysql_query($query);
      if ($debug) { print $query . "\n"; }
    }
    $valid[$type][$index] = 1;
  }
}

function discover_mempool(&$valid, $device, $index, $type, $descr, $precision = "1", $entPhysicalIndex = NULL, $hrDeviceIndex = NULL)
{
  global $config, $debug;

  if ($debug) { echo("$device, $oid, $index, $type, $descr, $precision, $current, $entPhysicalIndex, $hrDeviceIndex\n"); }
  if ($descr)
  {
    if (mysql_result(mysql_query("SELECT count(mempool_id) FROM `mempools` WHERE `mempool_index` = '$index' AND `device_id` = '".$device['device_id']."' AND `mempool_type` = '$type'"),0) == '0')
    {
      $query = "INSERT INTO mempools (`entPhysicalIndex`, `hrDeviceIndex`, `device_id`, `mempool_descr`, `mempool_index`, `mempool_type`, `mempool_precision`)
                      values ('$entPhysicalIndex', '$hrDeviceIndex', '".$device['device_id']."', '$descr', '$index', '$type','$precision')";
      mysql_query($query);
      if ($debug) { print $query . "\n"; }
      echo("+");
      log_event("Memory pool added: type ".mres($type)." index ".mres($index)." descr ". mres($descr), $device, 'mempool', mysql_insert_id());
    }
    else
    {
      echo(".");
      $query  = "UPDATE `mempools` SET `mempool_descr` = '".$descr."', `entPhysicalIndex` = '".$entPhysicalIndex."'";
      $query .= ", `hrDeviceIndex` = '$hrDeviceIndex' ";
      $query .= "WHERE `device_id` = '".$device['device_id']."' AND `mempool_index` = '".$index."' AND `mempool_type` = '".$type."'";
      mysql_query($query);
      if ($debug) { print $query . "\n"; }
    }
    $valid[$type][$index] = 1;
  }
}

function discover_toner(&$valid, $device, $oid, $index, $type, $descr, $capacity_oid = NULL, $capacity = NULL, $current = NULL)
{
  global $config, $debug;

  if ($debug) { echo("$oid, $index, $type, $descr, $capacity, $capacity_oid\n"); }

  if (mysql_result(mysql_query("SELECT count(toner_id) FROM `toner` WHERE device_id = '".$device['device_id']."' AND toner_type = '$type' AND `toner_index` = '$index' AND `toner_capacity_oid` = '$capacity_oid'"),0) == '0')
  {
    $query = "INSERT INTO toner (`device_id`, `toner_oid`, `toner_capacity_oid`, `toner_index`, `toner_type`, `toner_descr`, `toner_capacity`, `toner_current`) ";
    $query .= " VALUES ('".$device['device_id']."', '$oid', '$capacity_oid', '$index', '$type', '$descr', '$capacity', '$current')";
    mysql_query($query);
    echo("+");
    log_event("Toner added: type ".mres($type)." index ".mres($index)." descr ". mres($descr), $device, 'toner', mysql_insert_id());
  }
  else
  {
    $toner_entry = mysql_fetch_assoc(mysql_query("SELECT * FROM `toner` WHERE device_id = '".$device['device_id']."' AND toner_type = '$type' AND `toner_index` = '$index'"));
    if ($oid == $toner_entry['toner_oid'] && $descr == $toner_entry['toner_descr'] && $capacity == $toner_entry['toner_capacity'] && $capacity_oid == $toner_entry['toner_capacity_oid'])
    {
      echo(".");
    }
    else
    {
      mysql_query("UPDATE toner SET `toner_descr` = '$descr', `toner_oid` = '$oid', `toner_capacity_oid` = '$capacity_oid', `toner_capacity` = '$capacity' WHERE `device_id` = '".$device['device_id']."' AND toner_type = '$type' AND `toner_index` = '$index' ");
      echo("U");
    }
  }
  $valid[$type][$index] = 1;
}

function discover_process_ipv6(&$valid, $ifIndex,$ipv6_address,$ipv6_prefixlen,$ipv6_origin)
{
  global $device,$config;

  $ipv6_network = Net_IPv6::getNetmask("$ipv6_address/$ipv6_prefixlen") . '/' . $ipv6_prefixlen;
  $ipv6_compressed = Net_IPv6::compress($ipv6_address);

  if (Net_IPv6::getAddressType($ipv6_address) == NET_IPV6_LOCAL_LINK)
  {
    # ignore link-locals (coming from IPV6-MIB)
    return;
  }

  if (mysql_result(mysql_query("SELECT count(*) FROM `ports`
        WHERE device_id = '".$device['device_id']."' AND `ifIndex` = '$ifIndex'"), 0) != '0' && $ipv6_prefixlen > '0' && $ipv6_prefixlen < '129' && $ipv6_compressed != '::1')
  {
    $i_query = "SELECT port_id FROM `ports` WHERE device_id = '".$device['device_id']."' AND `ifIndex` = '$ifIndex'";
    $port_id = mysql_result(mysql_query($i_query), 0);
    if (mysql_result(mysql_query("SELECT COUNT(*) FROM `ipv6_networks` WHERE `ipv6_network` = '$ipv6_network'"), 0) < '1')
    {
      mysql_query("INSERT INTO `ipv6_networks` (`ipv6_network`) VALUES ('$ipv6_network')");
      echo("N");
    }

    if (mysql_result(mysql_query("SELECT COUNT(*) FROM `ipv6_networks` WHERE `ipv6_network` = '$ipv6_network'"), 0) < '1')
    {
      mysql_query("INSERT INTO `ipv6_networks` (`ipv6_network`) VALUES ('$ipv6_network')");
      echo("N");
    }

    $ipv6_network_id = @mysql_result(mysql_query("SELECT `ipv6_network_id` from `ipv6_networks` WHERE `ipv6_network` = '$ipv6_network'"), 0);

    if (mysql_result(mysql_query("SELECT COUNT(*) FROM `ipv6_addresses` WHERE `ipv6_address` = '$ipv6_address' AND `ipv6_prefixlen` = '$ipv6_prefixlen' AND `port_id` = '$port_id'"), 0) == '0')
    {
     mysql_query("INSERT INTO `ipv6_addresses` (`ipv6_address`, `ipv6_compressed`, `ipv6_prefixlen`, `ipv6_origin`, `ipv6_network_id`, `port_id`)
                                   VALUES ('$ipv6_address', '$ipv6_compressed', '$ipv6_prefixlen', '$ipv6_origin', '$ipv6_network_id', '$port_id')");
     echo("+");
    }
    else
    {
      echo(".");
    }
    $full_address = "$ipv6_address/$ipv6_prefixlen";
    $valid_address = $full_address  . "-" . $port_id;
    $valid['ipv6'][$valid_address] = 1;
  }
}

?>
