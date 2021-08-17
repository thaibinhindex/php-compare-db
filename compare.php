<?php

require_once(__DIR__."/DBLib.php");

$servername_src = $_ENV["DB_HOST_SRC"];
$username_src = $_ENV["DB_USERNAME_SRC"];
$password_src = $_ENV["DB_PASSWORD_SRC"];
$db_src = $_ENV["DB_NAME_SRC"];
$db_env_name_src = $_ENV["DB_ENV_NAME_SRC"];

$servername_dst = $_ENV["DB_HOST_DST"];
$username_dst = $_ENV["DB_USERNAME_DST"];
$password_dst = $_ENV["DB_PASSWORD_DST"];
$db_dst = $_ENV["DB_NAME_DST"];
$db_env_name_dst = $_ENV["DB_ENV_NAME_DST"];

function format_column($column) {
    $str = "`{$column['COLUMN_NAME']}` {$column['COLUMN_TYPE']} " . 
            ($column['COLLATION_NAME'] ? "COLLATE {$column['COLLATION_NAME']} " : '')  . 
            ( $column['IS_NULLABLE'] == "YES" ? '': 'NOT NULL ') .
            ($column['COLUMN_DEFAULT'] !== NULL ? "DEFAULT '{$column['COLUMN_DEFAULT']}' " : '') . 
            ($column['EXTRA'] ? "{$column['EXTRA']} " : ' ')

            ;
    return $str;
}

function format_index($value) {
    $str = "";
    switch($value['INDEX_NAME']) {
        case "PRIMARY":
            $str = "PRIMARY KEY (`{$value['columns']}`) USING {$value['INDEX_TYPE']}";
            break;
        case "FULLTEXT":
            $str = "FULLTEXT KEY (`{$value['columns']}`)";
            break;
        default:
            $str = ($value['NON_UNIQUE'] == 0 ? "UNIQUE " : "") . "KEY `{$value['INDEX_NAME']}` ({$value['columns']}) USING {$value['INDEX_TYPE']}";
            break;
    }
    return $str;
}

function compare_index($arr_src, $arr_dst) {
    $arr_result = [];

    if($arr_src) {
        foreach($arr_src as $key => $value) {
            $arr_result[$key]['src']['data'] = $value;
        }
    }
    
    if($arr_dst) {
        foreach($arr_dst as $key => $value) {
            $arr_result[$key]['dst']['data']  = $value;
        }
    }

    $arr_diff = [];
    foreach($arr_result as $key => $value) {
        if($value['src']['data'] != $value['dst']['data']) {
            $arr_diff[$key] = $value;
        }
    }
    return $arr_diff;
}

function compare_column($arr_src, $arr_dst) {
    $arr_result = [];
    foreach($arr_src as $key => $value) {
        $arr_result[$key]['src'] = $value;
        $arr_result[$key]['dst'] = ['data' => '', 'pos' => -1];
    }

    foreach($arr_dst as $key => $value) {
        $arr_result[$key]['dst'] = $value;
        if(!$arr_result[$key]['src']) {
            $arr_result[$key]['src'] = ['data' => '', 'pos' => -1];
        }
    }

    $arr_diff = []; 
    foreach($arr_result as $key => $value) {
        // if(!$value['dst'] || !$value['src']) var_dump($arr_result[$key]);
        if(array_diff_assoc($value['src'],
                        $value['dst'])) {
            $arr_diff[$key] = $value;
        }
    }
    return $arr_diff;
}

function line($value = "") {
    echo $value . PHP_EOL;
}
try {
    $conn_src = new DBLib($servername_src, $username_src, $password_src, $db_src);
    $pdo = $conn_src->getPDO();
    $arr_src = $conn_src->gen_structure();
    $conn_src = null;

    $conn_dst = new DBLib($servername_dst, $username_dst, $password_dst, $db_dst);
    $pdo = $conn_dst->getPDO();
    $arr_dst = $conn_dst->gen_structure();
    $conn_dst = null;

    $file_src = fopen("file_src.json", "w");
    fwrite($file_src, json_encode($arr_src,JSON_PRETTY_PRINT));
    fclose($file_src);

    $file_dst = fopen("file_dst.json", "w");
    fwrite($file_dst, json_encode($arr_dst,JSON_PRETTY_PRINT));
    fclose($file_dst);


    $only_left = array_diff_key($arr_src, $arr_dst);
    $table_skip = [];
    if(count($only_left)) {
        line("############################ TABLE ONLY ON `{$db_src}` ({$db_env_name_src}) ##################################");
        foreach($only_left as $table_name => $table_value) {
            $table_skip[] = $table_name; 
            
            line("\nTABLE: \n `$table_name`");
            line("COLUMNS:");
            foreach($table_value['columns'] as $key => $value) {
                line("  {$value['data']}");
            }
            echo PHP_EOL;
            echo "INDEX:\n";
            foreach($table_value['index'] as $key => $value) {
                line("  $value");
            }
            line ("------------------");
        }
    }
    

    $only_right = array_diff_key($arr_dst, $arr_src);
    if(count($only_right)) {
        line("\n\n############################# TABLE ONLY ON `{$db_dst}` ({$db_env_name_dst}) #####################################");
    
        foreach($only_right as $table_name => $table_value) {
            $table_skip[] = $table_name;
            line("\nTABLE: \n  `$table_name`");
            line("COLUMNS");
            foreach($table_value['columns'] as $key => $value) {
                line("  {$value['data']}");
            }
            echo PHP_EOL;
            line("INDEX:".PHP_EOL);
            foreach($table_value['index'] as $key => $value) {
                line("  $value");
            }
            line ("------------------");
        }
    }
    
    // table diff
    line("\n\n################################# TABLE DIFFERENT ######################################");
    foreach($arr_src as $table => $value) {
        $is_printed = false;
        if(in_array($table, $table_skip)) {
            continue;
        }
        
        // diff property
        if($arr_src[$table]['property'] != $arr_dst[$table]['property']) {
            line("TABLE \n  `$table`");
            $is_printed = true;
            line("PROPERTY");
            line("  + ".$arr_src[$table]['property']);
            line("  - ".$arr_dst[$table]['property']);
        }
        
        // diff index
        $diff_index = compare_index($arr_src[$table]['index'],$arr_dst[$table]['index']);
        if(count($diff_index)) {
            if(!$is_printed) {
                line("TABLE \n `$table`");
                $is_printed = true;
            }
            line ("INDEX");
            foreach($diff_index as $index_name => $value) {
                if($value["src"]["data"]) {
                    line("  + ". $value["src"]["data"] );
                }
                if($value["dst"]["data"]) {
                    line("  - ". $value["dst"]["data"] );
                }
            }
        }

        // diff_column
        $diff_column = compare_column($arr_src[$table]['columns'], $arr_dst[$table]['columns']);
        if(count($diff_column)) {
            if(!$is_printed) {
                line("TABLE \n  `$table`");
                $is_printed = true;
            }
            line("COLUMN");
            foreach($diff_column as $column_name => $column_data) {
                $column_data['src']['pos'] != -1 ? line ("  {$column_data['src']['pos']} + {$column_data['src']['data']}") : "";
                $column_data['dst']['pos'] != -1 ? line ("  {$column_data['dst']['pos']} - {$column_data['dst']['data']}") : "";
                line();
            }
        }
        

        $diff_constraint = compare_index($arr_src[$table]['constraint'], $arr_dst[$table]['constraint']);
        if(count($diff_constraint)) {
            if(!$is_printed) {
                line("TABLE \n `$table`");
                $is_printed = true;
            }

            line ("CONSTRAINT");
            foreach($diff_constraint as $constraint_name => $value) {
                if($value["src"]["data"]) {
                    line("  + ". $value["src"]["data"] );
                }
                if($value["dst"]["data"]) {
                    line("  - ". $value["dst"]["data"] );
                }
            }
        }

        $is_printed ?  line("-------------------") :  true;
    }
} catch(PDOException $e) {
  echo "Connection failed: " . $e->getMessage();
}
