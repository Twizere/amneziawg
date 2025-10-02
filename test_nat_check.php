<?php
// Test script to validate NAT rule checking functionality

// Mock pfSense configuration for testing
$config = array(
    'nat' => array(
        'outbound' => array(
            'rule' => array(
                array(
                    'interface' => 'wan',
                    'source' => array('network' => 'AWG_NET'),
                    'destination' => array('any' => true),
                    'target' => array('address' => 'WAN'),
                    'descr' => 'Allow AmneziaWG to go to WAN Interface'
                ),
                array(
                    'interface' => 'wan', 
                    'source' => array('network' => '10.0.1.0/24'),
                    'destination' => array('any' => true),
                    'target' => array('address' => 'WAN'),
                    'descr' => 'Direct tunnel network NAT'
                ),
                array(
                    'interface' => 'lan',
                    'source' => array('any' => true),
                    'destination' => array('any' => true),
                    'descr' => 'Not a WAN rule'
                )
            )
        )
    ),
    'aliases' => array(
        'alias' => array(
            array(
                'name' => 'AWG_NET',
                'type' => 'network',
                'address' => '10.0.1.0/24 10.0.2.0/24 10.0.3.0/24',
                'descr' => 'AmneziaWG Networks'
            ),
            array(
                'name' => 'WEB_PORTS',
                'type' => 'port',
                'address' => '80 443',
                'descr' => 'Web ports'
            )
        )
    )
);

// Mock tunnel networks for testing
$tunnel_networks = array(
    '10.0.1.0/24',
    '10.0.2.0/24',
    '10.0.4.0/24'  // This one won't be found in NAT rules
);

// Include our NAT checking function (copy from the main file)
function check_nat_rules($tunnel_networks) {
    global $config;
    
    $results = array();
    $nat_rules = isset($config['nat']['outbound']['rule']) ? 
                 $config['nat']['outbound']['rule'] : array();
    
    // Get aliases for network checking
    $aliases = isset($config['aliases']['alias']) ? 
               $config['aliases']['alias'] : array();
    $network_aliases = array();
    foreach ($aliases as $alias) {
        if ($alias['type'] == 'network') {
            $network_aliases[$alias['name']] = $alias['address'];
        }
    }
    
    // Debug information
    $debug_info = array();
    $debug_info['total_nat_rules'] = count($nat_rules);
    $debug_info['network_aliases_found'] = $network_aliases;
    $debug_info['tunnel_networks'] = $tunnel_networks;
    
    // Count WAN outbound NAT rules
    $wan_nat_rules = 0;
    $wan_nat_rules_debug = array();
    
    foreach ($nat_rules as $rule_index => $rule) {
        if (!isset($rule['disabled']) && 
            isset($rule['interface']) && $rule['interface'] == 'wan') {
            $wan_nat_rules++;
            $wan_nat_rules_debug[] = array(
                'index' => $rule_index,
                'description' => $rule['descr'] ?? 'Unnamed NAT rule',
                'source' => $rule['source'] ?? 'No source',
                'destination' => $rule['destination'] ?? 'No destination',
                'target' => $rule['target'] ?? 'No target'
            );
        }
    }
    
    $debug_info['wan_nat_rules_count'] = $wan_nat_rules;
    $debug_info['wan_nat_rules_sample'] = array_slice($wan_nat_rules_debug, 0, 5);
    
    // Add debug info as first result
    $results[] = array(
        'name' => 'NAT Rules Debug Information',
        'status' => 'pass',
        'message' => 'NAT configuration analysis',
        'details' => 'Total NAT rules: ' . $debug_info['total_nat_rules'] . 
                    ', WAN NAT rules: ' . $debug_info['wan_nat_rules_count'] . 
                    ', Network aliases: ' . count($debug_info['network_aliases_found']) .
                    (count($network_aliases) > 0 ? ' (' . implode(', ', array_keys($network_aliases)) . ')' : '')
    );
    
    // Check each tunnel network for NAT rules
    foreach ($tunnel_networks as $network) {
        $nat_allowed = false;
        $rule_details = '';
        $match_method = '';
        $debug_attempts = array();
        
        foreach ($nat_rules as $rule_index => $rule) {
            if (!isset($rule['disabled']) && 
                isset($rule['interface']) && $rule['interface'] == 'wan') {
                
                $debug_attempts[] = 'Checking NAT rule ' . $rule_index . ': ' . ($rule['descr'] ?? 'Unnamed');
                
                // Method 1: Check source network field
                if (isset($rule['source']['network'])) {
                    $source_network = $rule['source']['network'];
                    $debug_attempts[] = 'Rule has source network: ' . $source_network;
                    
                    // 1a. Direct network match
                    if ($source_network == $network || $source_network == 'any') {
                        $nat_allowed = true;
                        $rule_details = 'NAT Rule: ' . ($rule['descr'] ?? 'Unnamed rule');
                        $match_method = 'Direct network match';
                        $debug_attempts[] = 'MATCHED: Direct network match';
                        break;
                    }
                    
                    // 1b. Check if it's an alias reference
                    if (isset($network_aliases[$source_network])) {
                        $alias_value = $network_aliases[$source_network];
                        $debug_attempts[] = 'Found alias ' . $source_network . ' = ' . $alias_value;
                        
                        // Check if our tunnel network is in the alias
                        $alias_networks = explode(' ', $alias_value);
                        foreach ($alias_networks as $alias_network) {
                            $alias_network = trim($alias_network);
                            if ($alias_network == $network) {
                                $nat_allowed = true;
                                $rule_details = 'NAT Rule: ' . ($rule['descr'] ?? 'Unnamed rule') . ' (via alias: ' . $source_network . ')';
                                $match_method = 'Network alias match';
                                $debug_attempts[] = 'MATCHED: Network alias match';
                                break 2;
                            }
                        }
                    }
                } else {
                    $debug_attempts[] = 'Rule has no source network field';
                }
                
                // Method 2: Check if source is 'any'
                if (!$nat_allowed && isset($rule['source']['any'])) {
                    $nat_allowed = true;
                    $rule_details = 'NAT Rule: ' . ($rule['descr'] ?? 'Unnamed rule') . ' (Any source)';
                    $match_method = 'Any source';
                    $debug_attempts[] = 'MATCHED: Any source';
                    break;
                }
                
                // Method 3: Check direct source address match
                if (!$nat_allowed && isset($rule['source']['address'])) {
                    $source_address = $rule['source']['address'];
                    if ($source_address == $network) {
                        $nat_allowed = true;
                        $rule_details = 'NAT Rule: ' . ($rule['descr'] ?? 'Unnamed rule') . ' (Direct address match)';
                        $match_method = 'Direct address match';
                        $debug_attempts[] = 'MATCHED: Direct address match';
                        break;
                    }
                }
            }
        }
        
        $details_text = $nat_allowed ? $rule_details : 'Create an outbound NAT rule for network ' . $network . ' on WAN interface';
        if ($nat_allowed && $match_method) {
            $details_text .= ' [Match: ' . $match_method . ']';
        }
        
        // Add debug info for failed matches
        if (!$nat_allowed) {
            $details_text .= ' | Debug: ' . implode(' | ', array_slice($debug_attempts, 0, 10));
        }
        
        $results[] = array(
            'name' => 'NAT Rule for ' . $network,
            'status' => $nat_allowed ? 'pass' : 'fail',
            'message' => $nat_allowed ? 'NAT rule found for network' : 'No NAT rule found for network',
            'details' => $details_text
        );
    }
    
    // Check for AWG_NET alias specifically
    $awg_net_found = false;
    $awg_net_details = '';
    
    foreach ($network_aliases as $alias_name => $alias_value) {
        if (stripos($alias_name, 'awg') !== false || 
            stripos($alias_name, 'amnezia') !== false || 
            stripos($alias_name, 'wireguard') !== false) {
            
            // Check if this alias is used in any NAT rules
            $alias_used_in_nat = false;
            foreach ($nat_rules as $rule) {
                if (!isset($rule['disabled']) && 
                    isset($rule['interface']) && $rule['interface'] == 'wan' &&
                    isset($rule['source']['network']) && $rule['source']['network'] == $alias_name) {
                    $alias_used_in_nat = true;
                    $awg_net_details = 'Alias "' . $alias_name . '" used in NAT rule: ' . ($rule['descr'] ?? 'Unnamed rule');
                    break;
                }
            }
            
            if ($alias_used_in_nat) {
                $awg_net_found = true;
                break;
            }
        }
    }
    
    $results[] = array(
        'name' => 'AWG_NET Alias Usage in NAT',
        'status' => $awg_net_found ? 'pass' : 'warning',
        'message' => $awg_net_found ? 'AWG network alias found in NAT rules' : 'No AWG network alias found in NAT rules',
        'details' => $awg_net_found ? $awg_net_details : 
                    'Consider creating an alias called "AWG_NET" containing all tunnel networks for easier NAT rule management. ' .
                    'Available network aliases: ' . implode(', ', array_keys($network_aliases))
    );
    
    return $results;
}

// Run the test
echo "Testing NAT Rule Checking Functionality\n";
echo "=" . str_repeat("=", 40) . "\n\n";

$results = check_nat_rules($tunnel_networks);

foreach ($results as $result) {
    echo "Check: " . $result['name'] . "\n";
    echo "Status: " . strtoupper($result['status']) . "\n";
    echo "Message: " . $result['message'] . "\n";
    echo "Details: " . $result['details'] . "\n";
    echo "\n" . str_repeat("-", 60) . "\n\n";
}

echo "Test completed!\n";
?>