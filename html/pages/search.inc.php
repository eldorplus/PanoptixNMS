<?php

$pagetitle[] = "Search";

$sections = array('ipv4' => 'IPv4 Address', 'ipv6' => 'IPv6 Address', 'mac' => 'MAC Address', 'arp' => 'ARP Table');

if (!isset($vars['search'])) { $vars['search'] = "ipv4"; }

print_optionbar_start('', '');

 echo('<span style="font-weight: bold;">Search</span> &#187; ');

unset($sep);
foreach ($sections as $type => $texttype)
{
  echo($sep);
  if ($vars['search'] == $type)
  {
    echo("<span class='pagemenu-selected'>");
  }

#  echo('<a href="search/' . $type . ($_GET['optb'] ? '/' . $_GET['optb'] : ''). '/">' . $texttype .'</a>');
  echo(generate_link($texttype, array('page'=>'search','search'=>$type)));

  if ($vars['search'] == $type) { echo("</span>"); }

  $sep = ' | ';
}
unset ($sep);

print_optionbar_end('', '');

switch ($vars['search'])
{
  case 'ipv4':
  case 'ipv6':
  case 'mac':
  case 'arp':
    include('pages/search/'.$vars['search'].'.inc.php');
    break;
  default:
    echo("<h2>Error. Please report this to panoptixnms developers.</h2>");
    break;
}

?>
