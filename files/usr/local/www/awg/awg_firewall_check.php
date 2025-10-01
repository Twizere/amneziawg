<?php
/*
 * awg_firewall_check.php
 *
 * part of pfSense (https://www.pfsense.org)
 * Copyright (c) 2024 Rubicon Communications, LLC (Netgate)
 * Copyright (c) 2024 AmneziaWG Package
 * All rights reserved.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

require_once('guiconfig.inc');
require_once('functions.inc');
require_once('util.inc');
require_once('interfaces.inc');
// WireGuard includes
require_once('amneziawireguard/includes/wg.inc');
require_once('amneziawireguard/includes/wg_guiconfig.inc');
require_once('amneziawireguard/includes/wg_globals.inc');

global $wgg;

// Initialize $wgg state
wg_globals();

// Handle AJAX requests first (before any HTML output)
if (isset($_POST['action']) && $_POST['action'] == 'run_checks') {
    // Start output buffering to catch any unwanted output
    ob_start();
    
    try {
        // Clear any previous output
        if (ob_get_level()) {
            ob_clean();
        }
        
        // Disable all error output to prevent HTML in JSON response
        ini_set('display_errors', 0);
        ini_set('log_errors', 1);
        error_reporting(0);
        
        // Simple test first - just return basic JSON
        $test_response = array(
            'test' => 'basic_response',
            'status' => 'working',
            'message' => 'Basic JSON response successful'
        );
        
        // Add some debugging
        error_log("AmneziaWG Firewall Check: Starting checks");
        
        // Try the actual checks
        $checks_results = run_firewall_checks();
        
        error_log("AmneziaWG Firewall Check: Checks completed successfully");
        
        // Clear output buffer before sending JSON
        if (ob_get_level()) {
            ob_clean();
        }
        
        header('Content-Type: application/json');
        echo json_encode($checks_results);
    } catch (Exception $e) {
        // Clear output buffer on error too
        if (ob_get_level()) {
            ob_clean();
        }
        
        error_log("AmneziaWG Firewall Check Error: " . $e->getMessage());
        
        header('Content-Type: application/json');
        echo json_encode(array('error' => 'Failed to run checks: ' . $e->getMessage()));
    } catch (Throwable $e) {
        // Catch any PHP errors/warnings that might not be exceptions
        if (ob_get_level()) {
            ob_clean();
        }
        
        error_log("AmneziaWG Firewall Check Fatal Error: " . $e->getMessage());
        
        header('Content-Type: application/json');
        echo json_encode(array('error' => 'Fatal error: ' . $e->getMessage()));
    }
    exit;
}

function run_firewall_checks() {
    global $config, $wgg;
    
    // Suppress any warnings/notices to ensure clean JSON output
    $old_error_reporting = error_reporting(E_ERROR | E_PARSE);
    
    try {
        if (!isset($config) || !is_array($config)) {
            return array('error' => 'Configuration not available');
        }
        
        // Simple test to make sure we can return basic data
        if (!isset($config['installedpackages'])) {
            return array(
                'interface_groups' => array(),
                'wan_ports' => array(),
                'dns_wan' => array(),
                'firewall_rules' => array(),
                'aliases' => array(),
                'connectivity' => array(),
                'summary' => array('passed' => 0, 'failed' => 1, 'warnings' => 1),
                'test_message' => 'No installed packages found - AmneziaWG may not be installed'
            );
        }
        
        $results = array(
            'interface_groups' => array(),
            'wan_ports' => array(),
            'dns_wan' => array(),
            'firewall_rules' => array(),
            'aliases' => array(),
            'connectivity' => array(),
            'summary' => array('passed' => 0, 'failed' => 0, 'warnings' => 0)
        );
        
        // Get all AmneziaWG tunnels using global configuration path
        $xml_path = $wgg['xml_path'];
        $tunnels = isset($config[$xml_path[0]][$xml_path[1]]['tunnels']['item']) ? 
                   $config[$xml_path[0]][$xml_path[1]]['tunnels']['item'] : array();
        $tunnel_interfaces = array();
        $tunnel_ports = array();
        $tunnel_networks = array();
        
        if (is_array($tunnels)) {
            // Handle case where there's only one tunnel (not an array of arrays)
            if (isset($tunnels['name'])) {
                $tunnels = array($tunnels);
            }
            
            foreach ($tunnels as $tunnel) {
                if (is_array($tunnel) && !empty($tunnel['enabled']) && $tunnel['enabled'] == 'yes') {
                    if (!empty($tunnel['name'])) {
                        $tunnel_interfaces[] = $tunnel['name'];
                    }
                    if (!empty($tunnel['listenport'])) {
                        $tunnel_ports[] = intval($tunnel['listenport']);
                    }
                    
                    // Get tunnel networks
                    if (!empty($tunnel['addresses']['row'])) {
                        $addresses = $tunnel['addresses']['row'];
                        // Handle single address case
                        if (isset($addresses['address'])) {
                            $addresses = array($addresses);
                        }
                        
                        foreach ($addresses as $addr) {
                            if (is_array($addr) && !empty($addr['address']) && !empty($addr['mask'])) {
                                $tunnel_networks[] = $addr['address'] . '/' . $addr['mask'];
                            }
                        }
                    }
                }
            }
        }
        
        // Check 1: Interface Groups
        $results['interface_groups'] = check_interface_groups($tunnel_interfaces);
        
        // Check 2: WAN Port Rules for Tunnel Ports
        $results['wan_ports'] = check_wan_port_rules($tunnel_ports);
        
        // Check 3: DNS on WAN (port 53)
        $results['dns_wan'] = check_dns_wan_rules();
        
        // Check 4: Firewall Rules for Interface Groups
        $results['firewall_rules'] = check_interface_group_rules($tunnel_interfaces, $tunnel_networks);
        
        // Check 5: Aliases
        $results['aliases'] = check_aliases($tunnel_networks);
        
        // Check 6: Connectivity Tests
        $results['connectivity'] = check_connectivity($tunnel_interfaces);
        
        // Calculate summary
        foreach ($results as $category => $checks) {
            if ($category == 'summary') continue;
            
            if (is_array($checks)) {
                foreach ($checks as $check) {
                    if (isset($check['status'])) {
                        switch ($check['status']) {
                            case 'pass':
                                $results['summary']['passed']++;
                                break;
                            case 'fail':
                                $results['summary']['failed']++;
                                break;
                            case 'warning':
                                $results['summary']['warnings']++;
                                break;
                        }
                    }
                }
            }
        }
        
        // Restore error reporting
        error_reporting($old_error_reporting);
        
        return $results;
        
    } catch (Exception $e) {
        // Restore error reporting on error
        error_reporting($old_error_reporting);
        throw $e;
    }
}

function check_interface_groups($tunnel_interfaces) {
    global $config, $wgg;
    
    $results = array();
    $ifgroups = isset($config['ifgroups']['ifgroupentry']) ? 
                $config['ifgroups']['ifgroupentry'] : array();
    $awg_group_found = false;
    
    // Get interface group name from globals
    $awg_group_name = $wgg['ifgroupentry']['ifname'];
    
    // Also check system interface groups by running ifconfig to see actual group membership
    $interfaces_in_awg_group = array();
    foreach ($tunnel_interfaces as $tunnel_if) {
        $ifconfig_output = shell_exec("/sbin/ifconfig " . escapeshellarg($tunnel_if) . " 2>/dev/null");
        if ($ifconfig_output && (strpos($ifconfig_output, 'groups:') !== false)) {
            $groups_line = '';
            $lines = explode("\n", $ifconfig_output);
            foreach ($lines as $line) {
                if (strpos($line, 'groups:') !== false) {
                    $groups_line = $line;
                    break;
                }
            }
            if (strpos($groups_line, $awg_group_name) !== false) {
                $interfaces_in_awg_group[] = $tunnel_if;
            }
        }
    }
    
    foreach ($ifgroups as $group) {
        if (strpos($group['ifname'], $awg_group_name) !== false || strpos($group['ifname'], 'AWG') !== false) {
            $awg_group_found = true;
            $group_members = explode(' ', $group['members']);
            
            $results[] = array(
                'name' => 'Interface Group: ' . $group['ifname'],
                'status' => 'pass',
                'message' => $awg_group_name . ' interface group found with ' . count($group_members) . ' members',
                'details' => 'Members: ' . implode(', ', $group_members)
            );
        }
    }
    
    // Check system-level group membership
    if (count($interfaces_in_awg_group) > 0) {
        $results[] = array(
            'name' => 'System Interface Group Membership',
            'status' => 'pass',
            'message' => 'Tunnel interfaces are in ' . $awg_group_name . ' system group',
            'details' => 'Interfaces in ' . $awg_group_name . ' group: ' . implode(', ', $interfaces_in_awg_group)
        );
    }
    
    // Check for interfaces not in any group
    $ungrouped_interfaces = array();
    foreach ($tunnel_interfaces as $tunnel_if) {
        if (!in_array($tunnel_if, $interfaces_in_awg_group)) {
            $ungrouped_interfaces[] = $tunnel_if;
        }
    }
    
    if (count($ungrouped_interfaces) > 0) {
        $results[] = array(
            'name' => 'Ungrouped Interfaces',
            'status' => 'warning',
            'message' => 'Some tunnel interfaces are not in ' . $awg_group_name . ' group',
            'details' => 'Interfaces not in group: ' . implode(', ', $ungrouped_interfaces)
        );
    }
    
    if (!$awg_group_found && count($interfaces_in_awg_group) == 0) {
        $results[] = array(
            'name' => $awg_group_name . ' Interface Group',
            'status' => 'warning',
            'message' => 'No ' . $awg_group_name . ' interface group found',
            'details' => 'Consider creating an interface group for easier firewall rule management'
        );
    }
    
    return $results;
}

function check_wan_port_rules($tunnel_ports) {
    global $config;
    
    $results = array();
    $firewall_rules = isset($config['filter']['rule']) ? 
                      $config['filter']['rule'] : array();
    
    // Get aliases for port range checking
    $aliases = isset($config['aliases']['alias']) ? 
               $config['aliases']['alias'] : array();
    $port_aliases = array();
    foreach ($aliases as $alias) {
        if ($alias['type'] == 'port') {
            $port_aliases[$alias['name']] = $alias['address'];
        }
    }
    
    // Debug information - add this to see what we're working with
    $debug_info = array();
    $debug_info['total_firewall_rules'] = count($firewall_rules);
    $debug_info['total_port_aliases'] = count($port_aliases);
    $debug_info['port_aliases_found'] = $port_aliases;
    
    // Count WAN UDP rules
    $wan_udp_rules = 0;
    $wan_rules_debug = array();
    foreach ($firewall_rules as $rule_index => $rule) {
        if (!isset($rule['disabled']) && 
            isset($rule['interface']) && $rule['interface'] == 'wan' &&
            isset($rule['protocol']) && ($rule['protocol'] == 'udp' || $rule['protocol'] == 'any' || $rule['protocol'] == 'tcp/udp')) {
            $wan_udp_rules++;
            $wan_rules_debug[] = array(
                'index' => $rule_index,
                'description' => $rule['descr'] ?? 'Unnamed rule',
                'protocol' => $rule['protocol'],
                'destination' => $rule['destination'] ?? 'No destination',
                'source' => $rule['source'] ?? 'No source'
            );
        }
    }
    $debug_info['wan_udp_rules_count'] = $wan_udp_rules;
    $debug_info['wan_rules_sample'] = array_slice($wan_rules_debug, 0, 5); // First 5 rules for debugging
    
    // Add debug info as first result
    $results[] = array(
        'name' => 'Debug Information',
        'status' => 'pass',
        'message' => 'Configuration analysis',
        'details' => 'Total rules: ' . $debug_info['total_firewall_rules'] . 
                    ', WAN UDP rules: ' . $debug_info['wan_udp_rules_count'] . 
                    ', Port aliases: ' . $debug_info['total_port_aliases'] . 
                    (count($port_aliases) > 0 ? ' (' . implode(', ', array_keys($port_aliases)) . ')' : '')
    );
    
    foreach ($tunnel_ports as $port) {
        $port_allowed = false;
        $rule_details = '';
        $match_method = '';
        $debug_attempts = array();
        
        foreach ($firewall_rules as $rule_index => $rule) {
            if (!isset($rule['disabled']) && 
                isset($rule['interface']) && $rule['interface'] == 'wan' &&
                isset($rule['protocol']) && ($rule['protocol'] == 'udp' || $rule['protocol'] == 'any' || $rule['protocol'] == 'tcp/udp')) {
                
                $debug_attempts[] = 'Checking rule ' . $rule_index . ': ' . ($rule['descr'] ?? 'Unnamed');
                
                // Method 1: Check destination port field
                if (isset($rule['destination']['port'])) {
                    $dest_port = $rule['destination']['port'];
                    $debug_attempts[] = 'Rule has dest port: ' . $dest_port;
                    
                    // 1a. Direct port match (individual port)
                    if ($dest_port == $port || $dest_port == 'any') {
                        $port_allowed = true;
                        $rule_details = 'Rule: ' . ($rule['descr'] ?? 'Unnamed rule');
                        $match_method = 'Direct port match';
                        $debug_attempts[] = 'MATCHED: Direct port match';
                        break;
                    }
                    
                    // 1b. Check if it's an alias reference
                    if (isset($port_aliases[$dest_port])) {
                        $alias_value = $port_aliases[$dest_port];
                        $debug_attempts[] = 'Found alias ' . $dest_port . ' = ' . $alias_value;
                        
                        // Parse alias value for port ranges (e.g., "8081:8083", "8080-8090")
                        $range_separators = array(':', '-', ' - ', ' : ');
                        foreach ($range_separators as $separator) {
                            if (strpos($alias_value, $separator) !== false) {
                                $range_parts = explode($separator, $alias_value);
                                if (count($range_parts) == 2) {
                                    $start_port = intval(trim($range_parts[0]));
                                    $end_port = intval(trim($range_parts[1]));
                                    $debug_attempts[] = 'Checking range ' . $start_port . '-' . $end_port . ' for port ' . $port;
                                    if ($port >= $start_port && $port <= $end_port) {
                                        $port_allowed = true;
                                        $rule_details = 'Rule: ' . ($rule['descr'] ?? 'Unnamed rule') . ' (Alias: ' . $dest_port . ' = ' . $alias_value . ')';
                                        $match_method = 'Alias port range';
                                        $debug_attempts[] = 'MATCHED: Alias port range';
                                        break 2;
                                    }
                                }
                            }
                        }
                        
                        // Check if alias contains individual ports (space-separated list)
                        if (!$port_allowed) {
                            $alias_ports = preg_split('/[\s,]+/', trim($alias_value));
                            $debug_attempts[] = 'Checking individual ports in alias: ' . implode(', ', $alias_ports);
                            foreach ($alias_ports as $alias_port) {
                                if (intval(trim($alias_port)) == $port) {
                                    $port_allowed = true;
                                    $rule_details = 'Rule: ' . ($rule['descr'] ?? 'Unnamed rule') . ' (Alias: ' . $dest_port . ' contains port ' . $port . ')';
                                    $match_method = 'Alias individual port';
                                    $debug_attempts[] = 'MATCHED: Alias individual port';
                                    break 2;
                                }
                            }
                        }
                    } else {
                        $debug_attempts[] = 'No alias found for: ' . $dest_port;
                    }
                    
                    // 1c. Check for direct port ranges in destination port field
                    if (!$port_allowed) {
                        $range_separators = array('-', ':', ' - ', ' : ');
                        foreach ($range_separators as $separator) {
                            if (strpos($dest_port, $separator) !== false) {
                                $range_parts = explode($separator, $dest_port);
                                if (count($range_parts) == 2) {
                                    $start_port = intval(trim($range_parts[0]));
                                    $end_port = intval(trim($range_parts[1]));
                                    $debug_attempts[] = 'Checking direct range ' . $start_port . '-' . $end_port;
                                    if ($port >= $start_port && $port <= $end_port) {
                                        $port_allowed = true;
                                        $rule_details = 'Rule: ' . ($rule['descr'] ?? 'Unnamed rule') . ' (Direct port range: ' . $dest_port . ')';
                                        $match_method = 'Direct port range';
                                        $debug_attempts[] = 'MATCHED: Direct port range';
                                        break 2;
                                    }
                                }
                            }
                        }
                    }
                    
                    // 1d. Check for comma-separated individual ports in destination port field
                    if (!$port_allowed && strpos($dest_port, ',') !== false) {
                        $individual_ports = explode(',', $dest_port);
                        $debug_attempts[] = 'Checking comma-separated ports: ' . implode(', ', $individual_ports);
                        foreach ($individual_ports as $individual_port) {
                            if (intval(trim($individual_port)) == $port) {
                                $port_allowed = true;
                                $rule_details = 'Rule: ' . ($rule['descr'] ?? 'Unnamed rule') . ' (Multiple ports: ' . $dest_port . ')';
                                $match_method = 'Multiple individual ports';
                                $debug_attempts[] = 'MATCHED: Multiple individual ports';
                                break 2;
                            }
                        }
                    }
                } else {
                    $debug_attempts[] = 'Rule has no destination port field';
                }
                
                // Method 2: Check if there's a range in separate 'from' and 'to' fields
                if (!$port_allowed && isset($rule['destination']['from']) && isset($rule['destination']['to'])) {
                    $start_port = intval($rule['destination']['from']);
                    $end_port = intval($rule['destination']['to']);
                    $debug_attempts[] = 'Checking from/to range ' . $start_port . '-' . $end_port;
                    if ($port >= $start_port && $port <= $end_port) {
                        $port_allowed = true;
                        $rule_details = 'Rule: ' . ($rule['descr'] ?? 'Unnamed rule') . ' (Port range: ' . $start_port . '-' . $end_port . ')';
                        $match_method = 'Separate from/to fields';
                        $debug_attempts[] = 'MATCHED: Separate from/to fields';
                        break;
                    }
                }
                
                // Method 3: Check if destination is 'any' without port specified
                if (!$port_allowed && isset($rule['destination']['any'])) {
                    $port_allowed = true;
                    $rule_details = 'Rule: ' . ($rule['descr'] ?? 'Unnamed rule') . ' (Any destination)';
                    $match_method = 'Any destination';
                    $debug_attempts[] = 'MATCHED: Any destination';
                    break;
                }
                
                // Method 4: Check for destination network that might be 'any' 
                if (!$port_allowed && isset($rule['destination']['network']) && $rule['destination']['network'] == 'any') {
                    $port_allowed = true;
                    $rule_details = 'Rule: ' . ($rule['descr'] ?? 'Unnamed rule') . ' (Network: any)';
                    $match_method = 'Any network';
                    $debug_attempts[] = 'MATCHED: Any network';
                    break;
                }
            }
        }
        
        $details_text = $port_allowed ? $rule_details : 'Create a firewall rule to allow UDP port ' . $port . ' on WAN interface';
        if ($port_allowed && $match_method) {
            $details_text .= ' [Match: ' . $match_method . ']';
        }
        
        // Add debug info for failed matches
        if (!$port_allowed) {
            $details_text .= ' | Debug: ' . implode(' | ', array_slice($debug_attempts, 0, 10)); // Limit debug output
        }
        
        $results[] = array(
            'name' => 'WAN Port ' . $port . ' (UDP)',
            'status' => $port_allowed ? 'pass' : 'fail',
            'message' => $port_allowed ? 'Port is allowed on WAN' : 'Port is NOT allowed on WAN',
            'details' => $details_text
        );
    }
    
    return $results;
}

function check_dns_wan_rules() {
    global $config;
    
    $results = array();
    $firewall_rules = isset($config['filter']['rule']) ? 
                      $config['filter']['rule'] : array();
    $dns_allowed = false;
    $rule_details = '';
    
    foreach ($firewall_rules as $rule) {
        if (!isset($rule['disabled']) && 
            isset($rule['interface']) && $rule['interface'] == 'wan' &&
            isset($rule['destination']['port']) && 
            ($rule['destination']['port'] == '53' || $rule['destination']['port'] == 'any')) {
            
            $dns_allowed = true;
            $rule_details = 'Rule: ' . ($rule['descr'] ?? 'Unnamed rule');
            break;
        }
    }
    
    $results[] = array(
        'name' => 'DNS on WAN (Port 53)',
        'status' => $dns_allowed ? 'pass' : 'warning',
        'message' => $dns_allowed ? 'DNS queries allowed on WAN' : 'DNS queries may not be allowed on WAN',
        'details' => $dns_allowed ? $rule_details : 'Consider allowing DNS (port 53) on WAN if needed for tunnel clients'
    );
    
    return $results;
}

function check_interface_group_rules($tunnel_interfaces, $tunnel_networks) {
    global $config, $wgg;
    
    $results = array();
    $firewall_rules = isset($config['filter']['rule']) ? 
                      $config['filter']['rule'] : array();
    
    // Get interface group name from globals
    $awg_group_name = $wgg['ifgroupentry']['ifname'];
    
    // Debug information for interface group rules
    $debug_info = array();
    $debug_info['tunnel_interfaces'] = $tunnel_interfaces;
    $debug_info['total_firewall_rules'] = count($firewall_rules);
    $debug_info['awg_group_name'] = $awg_group_name;
    
    // Count rules by interface
    $rules_by_interface = array();
    $tunnel_related_rules = array();
    
    foreach ($firewall_rules as $rule_index => $rule) {
        if (!isset($rule['disabled']) && isset($rule['interface'])) {
            $interface = $rule['interface'];
            if (!isset($rules_by_interface[$interface])) {
                $rules_by_interface[$interface] = 0;
            }
            $rules_by_interface[$interface]++;
            
            // Check if this rule is tunnel-related
            if (in_array($interface, $tunnel_interfaces)) {
                $tunnel_related_rules[] = array(
                    'interface' => $interface,
                    'description' => $rule['descr'] ?? 'Unnamed rule',
                    'destination' => $rule['destination'] ?? 'No destination',
                    'protocol' => $rule['protocol'] ?? 'No protocol'
                );
            }
        }
    }
    
    $debug_info['rules_by_interface'] = $rules_by_interface;
    $debug_info['tunnel_related_rules_count'] = count($tunnel_related_rules);
    
    // Add debug info as first result
    $results[] = array(
        'name' => 'Interface Rules Debug',
        'status' => 'pass',
        'message' => 'Interface rules analysis',
        'details' => 'Tunnel interfaces: ' . implode(', ', $tunnel_interfaces) . 
                    ' | Total rules: ' . count($firewall_rules) . 
                    ' | Tunnel-specific rules: ' . count($tunnel_related_rules) .
                    ' | Interfaces with rules: ' . implode(', ', array_keys($rules_by_interface)) .
                    ' | Group name: ' . $awg_group_name
    );
    
    // Check for DNS rules on tunnel interfaces
    $dns_rules_found = false;
    $network_rules_found = false;
    $dns_rules_details = array();
    $network_rules_details = array();
    
    foreach ($firewall_rules as $rule) {
        if (isset($rule['interface'])) {
            $interface = $rule['interface'];
            
            // Check if rule is for tunnel interfaces or interface groups
            $is_tunnel_rule = false;
            $rule_type = '';
            
            if (in_array($interface, $tunnel_interfaces)) {
                $is_tunnel_rule = true;
                $rule_type = 'Direct tunnel interface';
            } else {
                // Check if it's an interface group containing tunnel interfaces
                $ifgroups = isset($config['ifgroups']['ifgroupentry']) ? 
                            $config['ifgroups']['ifgroupentry'] : array();
                
                // First check if it's a known AmneziaWG interface group name using globals
                if ($interface === $awg_group_name || strpos($interface, 'AWG') !== false || strpos($interface, 'amnezia') !== false) {
                    $is_tunnel_rule = true;
                    $rule_type = $awg_group_name . ' interface group';
                } else {
                    // Then check configured interface groups
                    foreach ($ifgroups as $group) {
                        if ($group['ifname'] == $interface) {
                            $group_members = explode(' ', $group['members']);
                            foreach ($tunnel_interfaces as $tunnel_if) {
                                if (in_array($tunnel_if, $group_members)) {
                                    $is_tunnel_rule = true;
                                    $rule_type = 'Interface group (' . $group['ifname'] . ')';
                                    break 2;
                                }
                            }
                        }
                    }
                }
            }
            
            if ($is_tunnel_rule && !isset($rule['disabled'])) {
                // Check for DNS rules
                if (isset($rule['destination']['port']) && $rule['destination']['port'] == '53') {
                    $dns_rules_found = true;
                    $dns_rules_details[] = array(
                        'interface' => $interface,
                        'type' => $rule_type,
                        'description' => $rule['descr'] ?? 'Unnamed rule'
                    );
                    $results[] = array(
                        'name' => 'DNS Rule on ' . $interface . ' (' . $rule_type . ')',
                        'status' => 'pass',
                        'message' => 'DNS rule found for tunnel interface',
                        'details' => 'Rule: ' . ($rule['descr'] ?? 'Unnamed rule')
                    );
                }
                
                // Check for network access rules
                if (isset($rule['destination']['network']) || isset($rule['destination']['any'])) {
                    $network_rules_found = true;
                    $network_rules_details[] = array(
                        'interface' => $interface,
                        'type' => $rule_type,
                        'description' => $rule['descr'] ?? 'Unnamed rule'
                    );
                    $results[] = array(
                        'name' => 'Network Rule on ' . $interface . ' (' . $rule_type . ')',
                        'status' => 'pass',
                        'message' => 'Network access rule found',
                        'details' => 'Rule: ' . ($rule['descr'] ?? 'Unnamed rule')
                    );
                }
            }
        }
    }
    
    if (!$dns_rules_found) {
        $results[] = array(
            'name' => 'DNS Rules for Tunnels',
            'status' => 'warning',
            'message' => 'No DNS rules found for tunnel interfaces',
            'details' => 'Consider adding rules to allow DNS (port 53) for tunnel clients. ' .
                        'Available tunnel interfaces: ' . implode(', ', $tunnel_interfaces) . 
                        '. Create rules on these interfaces or an interface group containing them.'
        );
    }
    
    if (!$network_rules_found) {
        $results[] = array(
            'name' => 'Network Rules for Tunnels',
            'status' => 'warning',
            'message' => 'No network access rules found for tunnel interfaces',
            'details' => 'Consider adding rules to allow network access for tunnel clients. ' .
                        'Available tunnel interfaces: ' . implode(', ', $tunnel_interfaces) . 
                        '. Create rules on these interfaces or an interface group containing them.'
        );
    }
    
    return $results;
}

function check_aliases($tunnel_networks) {
    global $config, $wgg;
    
    $results = array();
    $aliases = isset($config['aliases']['alias']) ? 
               $config['aliases']['alias'] : array();
    $awg_aliases_found = 0;
    
    foreach ($aliases as $alias) {
        $is_awg_alias = false;
        
        // Check if alias name suggests it's for AmneziaWG
        if (stripos($alias['name'], 'awg') !== false || 
            stripos($alias['name'], 'amnezia') !== false || 
            stripos($alias['name'], 'wireguard') !== false) {
            $is_awg_alias = true;
        }
        
        // Check if alias contains tunnel networks
        if (isset($alias['address'])) {
            $alias_addresses = explode(' ', $alias['address']);
            foreach ($tunnel_networks as $network) {
                if (in_array($network, $alias_addresses)) {
                    $is_awg_alias = true;
                    break;
                }
            }
        }
        
        if ($is_awg_alias) {
            $awg_aliases_found++;
            $results[] = array(
                'name' => 'Alias: ' . $alias['name'],
                'status' => 'pass',
                'message' => $wgg['ifgroupentry']['ifname'] . '-related alias found',
                'details' => 'Type: ' . $alias['type'] . ', Description: ' . ($alias['descr'] ?? 'No description')
            );
        }
    }
    
    if ($awg_aliases_found == 0) {
        $results[] = array(
            'name' => $wgg['ifgroupentry']['ifname'] . ' Aliases',
            'status' => 'warning',
            'message' => 'No ' . $wgg['ifgroupentry']['ifname'] . '-related aliases found',
            'details' => 'Consider creating aliases for tunnel networks for easier firewall rule management'
        );
    }
    
    return $results;
}

function check_connectivity($tunnel_interfaces) {
    $results = array();
    
    foreach ($tunnel_interfaces as $tunnel_if) {
        // Check if interface exists using ifconfig
        $ifconfig_output = shell_exec("/sbin/ifconfig " . escapeshellarg($tunnel_if) . " 2>/dev/null");
        $interface_exists = !empty($ifconfig_output);
        
        if ($interface_exists) {
            // Get the interface IP address for source-based ping
            $interface_ip = '';
            if (!empty($ifconfig_output)) {
                $lines = explode("\n", $ifconfig_output);
                foreach ($lines as $line) {
                    if (strpos($line, 'inet ') !== false && strpos($line, 'netmask') !== false) {
                        $parts = preg_split('/\s+/', trim($line));
                        for ($i = 0; $i < count($parts); $i++) {
                            if ($parts[$i] === 'inet' && isset($parts[$i + 1])) {
                                $interface_ip = $parts[$i + 1];
                                break 2;
                            }
                        }
                    }
                }
            }
            
            if (!empty($interface_ip)) {
                // Try to ping 8.8.8.8 using source IP from this interface (FreeBSD/pfSense syntax)
                $ping_cmd = "/sbin/ping -c 1 -W 3000 -S " . escapeshellarg($interface_ip) . " 8.8.8.8 2>&1";
                $ping_output = shell_exec($ping_cmd);
                $ping_success = (strpos($ping_output, '1 packets transmitted, 1 received') !== false || 
                               strpos($ping_output, '1 packets transmitted, 1 packets received') !== false);
                
                $results[] = array(
                    'name' => 'Connectivity via ' . $tunnel_if . ' (' . $interface_ip . ')',
                    'status' => $ping_success ? 'pass' : 'fail',
                    'message' => $ping_success ? 'Ping to 8.8.8.8 successful' : 'Ping to 8.8.8.8 failed',
                    'details' => $ping_success ? 'Interface is functional' : 'Check interface configuration and routing. Output: ' . trim(substr($ping_output, 0, 200))
                );
            } else {
                $results[] = array(
                    'name' => 'Interface ' . $tunnel_if,
                    'status' => 'warning',
                    'message' => 'Interface exists but no IP address found',
                    'details' => 'Interface ' . $tunnel_if . ' is present but may not be configured with an IP address'
                );
            }
        } else {
            $results[] = array(
                'name' => 'Interface ' . $tunnel_if,
                'status' => 'fail',
                'message' => 'Interface does not exist',
                'details' => 'Interface ' . $tunnel_if . ' is not present in the system'
            );
        }
    }
    
    return $results;
}

// Page header setup for non-AJAX requests
$active_tab = "Diagnostics";
include('amneziawireguard/includes/awg_header.inc');
$pgtitle[] = $active_tab;
include("head.inc");

wg_print_service_warning();

display_top_tabs($tab_array);

?>

<script type="text/javascript">
//<![CDATA[
function runChecks() {
    // Show loading
    $('#check-results').html('<div class="alert alert-info"><i class="fa fa-spinner fa-spin"></i> Running firewall and connectivity checks...</div>');
    
    // Disable button
    $('#run-checks-btn').prop('disabled', true);
    
    $.ajax({
        url: window.location.href,
        type: 'POST',
        data: { action: 'run_checks' },
        dataType: 'json',
        success: function(data) {
            if (data.error) {
                $('#check-results').html('<div class="alert alert-danger"><i class="fa fa-exclamation-triangle"></i> ' + data.error + '</div>');
            } else {
                displayResults(data);
            }
        },
        error: function(xhr, status, error) {
            var errorMsg = 'Error running checks. ';
            console.log('AJAX Error Details:', {
                status: xhr.status,
                statusText: xhr.statusText,
                responseText: xhr.responseText,
                error: error
            });
            
            if (xhr.responseText) {
                // Check if response starts with HTML/PHP tags
                if (xhr.responseText.trim().startsWith('<')) {
                    errorMsg += 'Server returned HTML instead of JSON. Check server logs for PHP errors.';
                } else {
                    try {
                        var response = JSON.parse(xhr.responseText);
                        errorMsg += response.error || response.message || 'Please try again.';
                    } catch (e) {
                        errorMsg += 'Invalid JSON response. Server response: ' + xhr.responseText.substring(0, 200);
                    }
                }
            } else {
                errorMsg += 'No response from server. HTTP ' + xhr.status + ' ' + error;
            }
            $('#check-results').html('<div class="alert alert-danger"><i class="fa fa-exclamation-triangle"></i> ' + errorMsg + '</div>');
        },
        complete: function() {
            $('#run-checks-btn').prop('disabled', false);
        }
    });
}

function displayResults(results) {
    var html = '';
    
    // Check for test message
    if (results.test_message) {
        html += '<div class="alert alert-warning"><i class="fa fa-info-circle"></i> ' + results.test_message + '</div>';
    }
    
    // Summary
    html += '<div class="panel panel-default">';
    html += '<div class="panel-heading"><h3>Summary</h3></div>';
    html += '<div class="panel-body">';
    html += '<div class="row">';
    html += '<div class="col-md-4"><span class="label label-success">Passed: ' + results.summary.passed + '</span></div>';
    html += '<div class="col-md-4"><span class="label label-danger">Failed: ' + results.summary.failed + '</span></div>';
    html += '<div class="col-md-4"><span class="label label-warning">Warnings: ' + results.summary.warnings + '</span></div>';
    html += '</div></div></div>';
    
    // Categories
    var categories = {
        'interface_groups': 'Interface Groups',
        'wan_ports': 'WAN Port Rules',
        'dns_wan': 'DNS on WAN',
        'firewall_rules': 'Firewall Rules',
        'aliases': 'Aliases',
        'connectivity': 'Connectivity Tests'
    };
    
    for (var category in categories) {
        if (results[category] && results[category].length > 0) {
            html += '<div class="panel panel-default">';
            html += '<div class="panel-heading"><h4>' + categories[category] + '</h4></div>';
            html += '<div class="panel-body">';
            
            for (var i = 0; i < results[category].length; i++) {
                var check = results[category][i];
                var statusClass = '';
                var statusIcon = '';
                
                switch (check.status) {
                    case 'pass':
                        statusClass = 'success';
                        statusIcon = 'fa-check';
                        break;
                    case 'fail':
                        statusClass = 'danger';
                        statusIcon = 'fa-times';
                        break;
                    case 'warning':
                        statusClass = 'warning';
                        statusIcon = 'fa-exclamation-triangle';
                        break;
                }
                
                html += '<div class="alert alert-' + statusClass + '">';
                html += '<i class="fa ' + statusIcon + '"></i> ';
                html += '<strong>' + check.name + ':</strong> ' + check.message;
                if (check.details) {
                    html += '<br><small>' + check.details + '</small>';
                }
                html += '</div>';
            }
            
            html += '</div></div>';
        }
    }
    
    $('#check-results').html(html);
}

// Auto-run checks on page load
$(document).ready(function() {
    runChecks();
});
//]]>
</script>

<div class="panel panel-default">
    <div class="panel-heading">
        <h2 class="panel-title"><?=gettext("AmneziaWG Firewall & Connectivity Check")?></h2>
    </div>
    <div class="panel-body">
        <div class="content">
            <p><?=gettext("This page performs comprehensive checks to validate your AmneziaWG firewall configuration and connectivity.")?></p>
            
            <div class="well">
                <h4><?=gettext("Checks Performed:")?></h4>
                <ul>
                    <li><?=gettext("Interface Groups - Verifies AmneziaWG interface groups are created")?></li>
                    <li><?=gettext("WAN Port Rules - Checks if tunnel ports are allowed on WAN interface")?></li>
                    <li><?=gettext("DNS on WAN - Verifies DNS (port 53) is allowed on WAN")?></li>
                    <li><?=gettext("Firewall Rules - Checks rules for DNS and network access on tunnel interfaces")?></li>
                    <li><?=gettext("Aliases - Looks for AmneziaWG-related aliases for network management")?></li>
                    <li><?=gettext("Connectivity - Tests connectivity through each tunnel interface (ping 8.8.8.8)")?></li>
                </ul>
            </div>
            
            <button id="run-checks-btn" class="btn btn-primary" onclick="runChecks()">
                <i class="fa fa-play"></i> <?=gettext("Run Checks")?>
            </button>
            
            <div id="check-results" style="margin-top: 20px;">
                <!-- Results will be displayed here -->
            </div>
        </div>
    </div>
</div>

<?php include("foot.inc"); ?>