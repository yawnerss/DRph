<?php
/**
 * MySQL Database Connection Tool with Full Database Export
 * Export entire database as SQL dump
 */

// Start session to persist connection
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================================
// CONFIGURATION
// ============================================================
$DB_HOST = $_SESSION['db_host'] ?? 'localhost';
$DB_PORT = $_SESSION['db_port'] ?? '3306';
$DB_USER = $_SESSION['db_user'] ?? '';
$DB_PASSWORD = $_SESSION['db_password'] ?? '';
$DB_NAME = $_SESSION['db_name'] ?? '';

// ============================================================
// HANDLE FORM SUBMISSION
// ============================================================
$connected = isset($_SESSION['db_connected']) && $_SESSION['db_connected'] === true;
$connection = null;
$error = '';
$result = '';
$tables = [];
$query_result = [];
$table_data = [];
$selected_table = '';
$total_rows = 0;
$edit_result = '';
$sql_message = '';

// Handle connection
if (isset($_POST['connect'])) {
    $DB_HOST = $_POST['host'] ?? 'localhost';
    $DB_PORT = $_POST['port'] ?? '3306';
    $DB_USER = $_POST['user'] ?? '';
    $DB_PASSWORD = $_POST['password'] ?? '';
    $DB_NAME = $_POST['database'] ?? '';
    
    $_SESSION['db_host'] = $DB_HOST;
    $_SESSION['db_port'] = $DB_PORT;
    $_SESSION['db_user'] = $DB_USER;
    $_SESSION['db_password'] = $DB_PASSWORD;
    $_SESSION['db_name'] = $DB_NAME;
    
    try {
        $dsn = "mysql:host=$DB_HOST;port=$DB_PORT;dbname=$DB_NAME;charset=utf8mb4";
        $connection = new PDO($dsn, $DB_USER, $DB_PASSWORD);
        $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $_SESSION['db_connected'] = true;
        $connected = true;
        
        $stmt = $connection->query("SHOW TABLES");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
    } catch (PDOException $e) {
        $error = "Connection failed: " . $e->getMessage();
        $_SESSION['db_connected'] = false;
        $connected = false;
    }
}

// If connected from session, reconnect
if ($connected && $connection === null) {
    try {
        $dsn = "mysql:host=$DB_HOST;port=$DB_PORT;dbname=$DB_NAME;charset=utf8mb4";
        $connection = new PDO($dsn, $DB_USER, $DB_PASSWORD);
        $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $stmt = $connection->query("SHOW TABLES");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
    } catch (PDOException $e) {
        $error = "Reconnection failed: " . $e->getMessage();
        $_SESSION['db_connected'] = false;
        $connected = false;
    }
}

// ============================================================
// EXPORT DATABASE
// ============================================================
if (isset($_GET['export_db']) && $connected) {
    try {
        if ($connection === null) {
            $dsn = "mysql:host=$DB_HOST;port=$DB_PORT;dbname=$DB_NAME;charset=utf8mb4";
            $connection = new PDO($dsn, $DB_USER, $DB_PASSWORD);
            $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }
        
        // Get all tables
        $stmt = $connection->query("SHOW TABLES");
        $all_tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Get database name
        $db_name = $DB_NAME;
        
        // Start output buffering
        $output = "-- =============================================\n";
        $output .= "-- Database: $db_name\n";
        $output .= "-- Export Date: " . date('Y-m-d H:i:s') . "\n";
        $output .= "-- =============================================\n\n";
        $output .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";
        $output .= "DROP DATABASE IF EXISTS `$db_name`;\n";
        $output .= "CREATE DATABASE IF NOT EXISTS `$db_name`;\n";
        $output .= "USE `$db_name`;\n\n";
        
        // Get each table
        foreach ($all_tables as $table) {
            // Get table structure
            $stmt2 = $connection->query("SHOW CREATE TABLE `$table`");
            $create = $stmt2->fetch(PDO::FETCH_ASSOC);
            if ($create) {
                $create_sql = $create['Create Table'];
                $output .= "-- -----------------------------\n";
                $output .= "-- Table structure for `$table`\n";
                $output .= "-- -----------------------------\n";
                $output .= "DROP TABLE IF EXISTS `$table`;\n";
                $output .= $create_sql . ";\n\n";
            }
            
            // Get table data
            $stmt3 = $connection->query("SELECT * FROM `$table`");
            $rows = $stmt3->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($rows)) {
                $output .= "-- -----------------------------\n";
                $output .= "-- Data for `$table` (" . count($rows) . " rows)\n";
                $output .= "-- -----------------------------\n";
                
                $columns = array_keys($rows[0]);
                $columns_escaped = array_map(function($col) { return "`$col`"; }, $columns);
                $col_str = implode(', ', $columns_escaped);
                
                foreach ($rows as $row) {
                    $values = [];
                    foreach ($row as $value) {
                        if (is_null($value)) {
                            $values[] = "NULL";
                        } elseif (is_numeric($value) && strlen($value) < 20) {
                            $values[] = $value;
                        } else {
                            $values[] = "'" . addslashes($value) . "'";
                        }
                    }
                    $output .= "INSERT INTO `$table` ($col_str) VALUES (" . implode(', ', $values) . ");\n";
                }
                $output .= "\n";
            }
        }
        
        $output .= "SET FOREIGN_KEY_CHECKS = 1;\n";
        
        // Set headers for download
        header('Content-Type: application/sql');
        header('Content-Disposition: attachment; filename="' . $db_name . '_' . date('Y-m-d') . '.sql"');
        header('Content-Length: ' . strlen($output));
        
        echo $output;
        exit;
        
    } catch (Exception $e) {
        $error = "Export error: " . $e->getMessage();
    }
}

// ============================================================
// TABLE OPERATIONS
// ============================================================

// Handle viewing table
if (isset($_POST['view_table']) && $connected) {
    $selected_table = $_POST['table_name'];
    $_SESSION['selected_table'] = $selected_table;
    
    try {
        if ($connection === null) {
            $dsn = "mysql:host=$DB_HOST;port=$DB_PORT;dbname=$DB_NAME;charset=utf8mb4";
            $connection = new PDO($dsn, $DB_USER, $DB_PASSWORD);
            $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }
        
        $count_stmt = $connection->query("SELECT COUNT(*) FROM `$selected_table`");
        $total_rows = $count_stmt->fetchColumn();
        
        $stmt = $connection->query("SELECT * FROM `$selected_table`");
        $table_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $result = "Table: $selected_table | Total rows: " . number_format($total_rows) . " | Showing: " . number_format(count($table_data)) . " rows";
    } catch (PDOException $e) {
        $result = "Error viewing table: " . $e->getMessage();
    }
}

// INSERT operation
if (isset($_POST['insert_row']) && $connected) {
    $table = $_POST['table_name'];
    $columns = $_POST['columns'] ?? [];
    $values = $_POST['values'] ?? [];
    
    if (!empty($columns) && !empty($values) && count($columns) === count($values)) {
        try {
            if ($connection === null) {
                $dsn = "mysql:host=$DB_HOST;port=$DB_PORT;dbname=$DB_NAME;charset=utf8mb4";
                $connection = new PDO($dsn, $DB_USER, $DB_PASSWORD);
                $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            }
            
            $cols = implode('`, `', $columns);
            $placeholders = implode(', ', array_fill(0, count($values), '?'));
            
            $sql = "INSERT INTO `$table` (`$cols`) VALUES ($placeholders)";
            $stmt = $connection->prepare($sql);
            $stmt->execute($values);
            
            $sql_message = "✅ INSERT successful! Row added to $table. Affected rows: " . $stmt->rowCount();
            
            // Refresh table data
            $stmt2 = $connection->query("SELECT * FROM `$table`");
            $table_data = $stmt2->fetchAll(PDO::FETCH_ASSOC);
            $total_rows = count($table_data);
            $result = "Table: $table | Total rows: " . number_format($total_rows);
            
        } catch (PDOException $e) {
            $sql_message = "❌ INSERT failed: " . $e->getMessage();
        }
    } else {
        $sql_message = "❌ Columns and values count mismatch";
    }
}

// UPDATE operation
if (isset($_POST['update_row']) && $connected) {
    $table = $_POST['table_name'];
    $primary_key = $_POST['primary_key'] ?? 'id';
    $primary_value = $_POST['primary_value'] ?? '';
    $update_columns = $_POST['update_columns'] ?? [];
    $update_values = $_POST['update_values'] ?? [];
    
    if (!empty($update_columns) && !empty($update_values) && count($update_columns) === count($update_values) && $primary_value) {
        try {
            if ($connection === null) {
                $dsn = "mysql:host=$DB_HOST;port=$DB_PORT;dbname=$DB_NAME;charset=utf8mb4";
                $connection = new PDO($dsn, $DB_USER, $DB_PASSWORD);
                $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            }
            
            $sets = [];
            foreach ($update_columns as $i => $col) {
                $sets[] = "`$col` = ?";
            }
            $sets_str = implode(', ', $sets);
            
            $sql = "UPDATE `$table` SET $sets_str WHERE `$primary_key` = ?";
            $params = array_merge($update_values, [$primary_value]);
            $stmt = $connection->prepare($sql);
            $stmt->execute($params);
            
            $sql_message = "✅ UPDATE successful! Row updated in $table. Affected rows: " . $stmt->rowCount();
            
            // Refresh table data
            $stmt2 = $connection->query("SELECT * FROM `$table`");
            $table_data = $stmt2->fetchAll(PDO::FETCH_ASSOC);
            $total_rows = count($table_data);
            $result = "Table: $table | Total rows: " . number_format($total_rows);
            
        } catch (PDOException $e) {
            $sql_message = "❌ UPDATE failed: " . $e->getMessage();
        }
    } else {
        $sql_message = "❌ Missing update data";
    }
}

// DELETE operation
if (isset($_POST['delete_row']) && $connected) {
    $table = $_POST['table_name'];
    $primary_key = $_POST['primary_key'] ?? 'id';
    $primary_value = $_POST['primary_value'] ?? '';
    
    if ($primary_value) {
        try {
            if ($connection === null) {
                $dsn = "mysql:host=$DB_HOST;port=$DB_PORT;dbname=$DB_NAME;charset=utf8mb4";
                $connection = new PDO($dsn, $DB_USER, $DB_PASSWORD);
                $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            }
            
            $sql = "DELETE FROM `$table` WHERE `$primary_key` = ?";
            $stmt = $connection->prepare($sql);
            $stmt->execute([$primary_value]);
            
            $sql_message = "✅ DELETE successful! Row removed from $table. Affected rows: " . $stmt->rowCount();
            
            // Refresh table data
            $stmt2 = $connection->query("SELECT * FROM `$table`");
            $table_data = $stmt2->fetchAll(PDO::FETCH_ASSOC);
            $total_rows = count($table_data);
            $result = "Table: $table | Total rows: " . number_format($total_rows);
            
        } catch (PDOException $e) {
            $sql_message = "❌ DELETE failed: " . $e->getMessage();
        }
    } else {
        $sql_message = "❌ Missing primary key value";
    }
}

// Custom SQL query
if (isset($_POST['query']) && $connected) {
    $query = $_POST['query'];
    try {
        if ($connection === null) {
            $dsn = "mysql:host=$DB_HOST;port=$DB_PORT;dbname=$DB_NAME;charset=utf8mb4";
            $connection = new PDO($dsn, $DB_USER, $DB_PASSWORD);
            $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }
        
        $stmt = $connection->query($query);
        if ($stmt) {
            $query_result = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $result = "✅ Query executed successfully. " . number_format(count($query_result)) . " rows returned.";
        }
    } catch (PDOException $e) {
        $result = "❌ Query error: " . $e->getMessage();
    }
}

// Handle WordPress DB detection
if (isset($_POST['detect_wp'])) {
    $wp_path = $_POST['wp_path'] ?? '';
    
    if (empty($wp_path)) {
        $possible_paths = [
            __DIR__,
            __DIR__ . '/..',
            __DIR__ . '/../..',
            __DIR__ . '/../../..',
            $_SERVER['DOCUMENT_ROOT'],
            $_SERVER['DOCUMENT_ROOT'] . '/..'
        ];
        
        foreach ($possible_paths as $path) {
            $wp_config = $path . '/wp-config.php';
            if (file_exists($wp_config)) {
                $wp_path = $path;
                break;
            }
        }
    }
    
    if (!empty($wp_path) && file_exists($wp_path . '/wp-config.php')) {
        $wp_config = $wp_path . '/wp-config.php';
        $content = file_get_contents($wp_config);
        
        preg_match("/define\s*\(\s*['\"]DB_NAME['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", $content, $name);
        preg_match("/define\s*\(\s*['\"]DB_USER['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", $content, $user);
        preg_match("/define\s*\(\s*['\"]DB_PASSWORD['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", $content, $pass);
        preg_match("/define\s*\(\s*['\"]DB_HOST['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", $content, $host);
        
        if ($name && $user && $pass && $host) {
            $_SESSION['db_name'] = $name[1];
            $_SESSION['db_user'] = $user[1];
            $_SESSION['db_password'] = $pass[1];
            $_SESSION['db_host'] = $host[1];
            
            $DB_NAME = $name[1];
            $DB_USER = $user[1];
            $DB_PASSWORD = $pass[1];
            $DB_HOST = $host[1];
            $error = "WordPress credentials found! Click Connect to use them.";
        } else {
            $error = "Could not parse wp-config.php.";
        }
    } else {
        $error = "wp-config.php not found. Please enter credentials manually.";
    }
}

// Handle disconnect
if (isset($_GET['disconnect'])) {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Export as CSV
if (isset($_GET['export_csv']) && isset($_GET['table'])) {
    $table = $_GET['table'];
    try {
        $dsn = "mysql:host=$DB_HOST;port=$DB_PORT;dbname=$DB_NAME;charset=utf8mb4";
        $conn = new PDO($dsn, $DB_USER, $DB_PASSWORD);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $stmt = $conn->query("SELECT * FROM `$table`");
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($data)) {
            die("No data to export");
        }
        
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $table . '_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, array_keys($data[0]));
        foreach ($data as $row) {
            fputcsv($output, $row);
        }
        fclose($output);
        exit;
    } catch (Exception $e) {
        die("Export error: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>MySQL Connection Tool</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background: #0a0e17;
            font-family: 'Courier New', monospace;
            color: #c0d0e0;
            padding: 20px;
            background-image: url('https://www.image2url.com/r2/default/files/1781528594852-016ffb8e-63f6-48f2-87f9-e25e3bd94512.png');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            min-height: 100vh;
        }
        body::before {
            content: '';
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(10, 14, 23, 0.9);
            z-index: 0;
        }
        .container {
            position: relative;
            z-index: 1;
            max-width: 1400px;
            margin: 0 auto;
        }
        .header {
            background: rgba(15, 20, 31, 0.95);
            border-bottom: 2px solid #2ecc71;
            padding: 15px 20px;
            border-radius: 10px 10px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            backdrop-filter: blur(10px);
        }
        .header .logo { display: flex; align-items: center; gap: 15px; }
        .header .logo img { height: 40px; border-radius: 6px; }
        .header h1 { color: #2ecc71; font-size: 20px; font-weight: normal; }
        .header .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
        }
        .status-badge.on { background: rgba(46,204,113,0.2); color: #2ecc71; border: 1px solid #2ecc71; }
        .status-badge.off { background: rgba(231,76,60,0.2); color: #e74c3c; border: 1px solid #e74c3c; }
        .card {
            background: rgba(15, 20, 31, 0.95);
            border: 1px solid #1e2a3a;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 15px;
            backdrop-filter: blur(10px);
        }
        .card h3 { color: #2ecc71; font-size: 14px; border-bottom: 1px solid #1e2a3a; padding-bottom: 10px; margin-bottom: 15px; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; }
        input, textarea, select {
            background: rgba(10, 14, 23, 0.9);
            border: 1px solid #1e2a3a;
            color: #c0d0e0;
            font-family: 'Courier New', monospace;
            padding: 8px 12px;
            border-radius: 4px;
            width: 100%;
            font-size: 13px;
            margin-bottom: 10px;
        }
        input:focus, textarea:focus { border-color: #2ecc71; outline: none; }
        textarea { font-family: 'Courier New', monospace; }
        button {
            background: #1e2a3a;
            border: 1px solid #2ecc71;
            color: #2ecc71;
            padding: 8px 20px;
            border-radius: 4px;
            cursor: pointer;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            transition: 0.2s;
        }
        button:hover { background: #2ecc71; color: #0a0e17; }
        button.danger { border-color: #e74c3c; color: #e74c3c; }
        button.danger:hover { background: #e74c3c; color: #fff; }
        button.secondary { border-color: #21759b; color: #21759b; }
        button.secondary:hover { background: #21759b; color: #fff; }
        button.warning { border-color: #f39c12; color: #f39c12; }
        button.warning:hover { background: #f39c12; color: #0a0e17; }
        button.success { border-color: #2ecc71; color: #2ecc71; }
        button.success:hover { background: #2ecc71; color: #0a0e17; }
        button.export-btn { border-color: #9b59b6; color: #9b59b6; }
        button.export-btn:hover { background: #9b59b6; color: #fff; }
        .status {
            padding: 8px 12px;
            border-radius: 4px;
            margin: 10px 0;
            font-size: 13px;
        }
        .status.success { background: rgba(46, 204, 113, 0.2); color: #2ecc71; border: 1px solid #2ecc71; }
        .status.error { background: rgba(231, 76, 60, 0.2); color: #e74c3c; border: 1px solid #e74c3c; }
        .status.info { background: rgba(52, 152, 219, 0.2); color: #3498db; border: 1px solid #3498db; }
        .status.warning { background: rgba(243, 156, 18, 0.2); color: #f39c12; border: 1px solid #f39c12; }
        .table-wrap { overflow-x: auto; max-height: 400px; overflow-y: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        th { 
            text-align: left; 
            color: #2ecc71; 
            padding: 6px 8px; 
            border-bottom: 2px solid #2ecc71;
            background: rgba(10, 14, 23, 0.95);
            position: sticky;
            top: 0;
            z-index: 10;
        }
        td { padding: 4px 8px; border-bottom: 1px solid #1a2535; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        tr:hover { background: rgba(26, 37, 53, 0.5); }
        .flex-row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .flex-row input { flex: 1; margin-bottom: 0; }
        .flex-row button { flex-shrink: 0; }
        .table-btn {
            background: rgba(46,204,113,0.05);
            border: 1px solid #1e2a3a;
            padding: 6px 14px;
            border-radius: 4px;
            cursor: pointer;
            color: #c0d0e0;
            font-size: 12px;
            transition: 0.2s;
            margin: 3px;
            font-family: 'Courier New', monospace;
        }
        .table-btn:hover { border-color: #2ecc71; color: #2ecc71; background: rgba(46,204,113,0.1); }
        .table-btn.active { border-color: #2ecc71; background: rgba(46,204,113,0.15); color: #2ecc71; }
        .table-stats { color: #4a627a; font-size: 12px; margin-left: 10px; }
        .export-badge { background: #9b59b6; color: #fff; padding: 2px 8px; border-radius: 4px; font-size: 10px; margin-left: 5px; }
        @media (max-width: 768px) { .grid { grid-template-columns: 1fr; } .grid-3 { grid-template-columns: 1fr; } }
        .copy-btn { background: rgba(46,204,113,0.1); border: 1px solid #1e2a3a; padding: 2px 8px; border-radius: 3px; cursor: pointer; font-size: 10px; color: #4a627a; }
        .copy-btn:hover { background: rgba(46,204,113,0.3); }
        .export-btn-link { background: rgba(155,89,182,0.1); border: 1px solid #1e2a3a; padding: 2px 8px; border-radius: 3px; cursor: pointer; font-size: 10px; color: #9b59b6; text-decoration: none; }
        .export-btn-link:hover { background: rgba(155,89,182,0.3); }
        .disconnect-btn { background: rgba(231,76,60,0.1); border: 1px solid #1e2a3a; padding: 2px 8px; border-radius: 3px; cursor: pointer; font-size: 10px; color: #e74c3c; text-decoration: none; margin-left: 10px; }
        .disconnect-btn:hover { background: rgba(231,76,60,0.3); }
        .table-tabs { display: flex; flex-wrap: wrap; gap: 4px; max-height: 150px; overflow-y: auto; padding: 5px; background: rgba(10,14,23,0.5); border-radius: 4px; }
        .edit-section { background: rgba(10,14,23,0.5); border-radius: 4px; padding: 15px; margin-top: 15px; }
        .edit-section .title { color: #f39c12; font-size: 13px; margin-bottom: 10px; }
        .row-edit { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 5px; }
        .row-edit input { flex: 1; min-width: 100px; margin: 0; }
        .row-edit .col-label { color: #4a627a; font-size: 11px; min-width: 80px; }
        .modal {
            display: none; position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%);
            background: rgba(15, 20, 31, 0.98); border: 2px solid #2ecc71; padding: 25px; border-radius: 10px;
            z-index: 1000; min-width: 500px; max-width: 900px; max-height: 80vh; overflow: auto;
            backdrop-filter: blur(20px);
        }
        .modal h3 { color: #2ecc71; margin-bottom: 15px; }
        .modal .close-btn { float: right; cursor: pointer; color: #e74c3c; font-size: 24px; }
        .modal .close-btn:hover { color: #fff; }
        #overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 999; }
        .edit-actions { display: flex; gap: 8px; margin-top: 10px; flex-wrap: wrap; }
        .small-btn { font-size: 10px; padding: 2px 8px; margin: 1px; }
        .id-col { color: #f39c12; font-weight: bold; }
        .sql-message { padding: 10px; border-radius: 4px; margin: 10px 0; font-size: 13px; }
        .export-section { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
    </style>
</head>
<body>
<div class="container">

<div class="header">
    <div class="logo">
        <img src="https://www.image2url.com/r2/default/files/1781528594852-016ffb8e-63f6-48f2-87f9-e25e3bd94512.png" alt="Logo">
        <h1>⚡ MySQL Connect & Edit</h1>
    </div>
    <div>
        <span class="status-badge <?= $connected ? 'on' : 'off' ?>">
            <?= $connected ? '✅ Connected' : '🔴 Disconnected' ?>
        </span>
        <?php if ($connected): ?>
            <a href="?disconnect=1" class="disconnect-btn">🔌 Disconnect</a>
        <?php endif; ?>
    </div>
</div>

<!-- Connection Form -->
<div class="card">
    <h3>🔌 Database Connection</h3>
    
    <?php if ($error): ?>
        <div class="status <?= strpos($error, 'found') !== false ? 'success' : 'error' ?>">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>
    
    <?php if ($connected): ?>
        <div class="status success">
            ✅ Connected to <strong><?= htmlspecialchars($DB_NAME) ?></strong> on <strong><?= htmlspecialchars($DB_HOST) ?></strong>
            <?php if (!empty($tables)): ?>
                | <?= count($tables) ?> tables found
            <?php endif; ?>
        </div>
    <?php endif; ?>
    
    <form method="POST">
        <div class="grid-3">
            <input type="text" name="host" value="<?= htmlspecialchars($DB_HOST) ?>" placeholder="Host (localhost)">
            <input type="text" name="port" value="<?= htmlspecialchars($DB_PORT) ?>" placeholder="Port (3306)">
            <input type="text" name="database" value="<?= htmlspecialchars($DB_NAME) ?>" placeholder="Database name">
        </div>
        <div class="grid">
            <input type="text" name="user" value="<?= htmlspecialchars($DB_USER) ?>" placeholder="Username">
            <input type="password" name="password" value="<?= htmlspecialchars($DB_PASSWORD) ?>" placeholder="Password">
        </div>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <button type="submit" name="connect" style="flex:1;">🔗 Connect</button>
            <button type="submit" name="detect_wp" class="secondary" style="flex:1;">📂 Auto Detect WP</button>
        </div>
        <div style="margin-top:10px;">
            <input type="text" name="wp_path" placeholder="Or enter path to WordPress root (e.g., /var/www/html)" style="width:100%;">
        </div>
    </form>
</div>

<?php if ($connected): ?>

<!-- Export Database -->
<div class="card">
    <h3>💾 Database Export <span class="export-badge">NEW</span></h3>
    <div class="export-section">
        <a href="?export_db=1" class="export-btn-link" style="padding:8px 20px; font-size:13px; display:inline-block; border-color:#9b59b6; color:#9b59b6; background:rgba(155,89,182,0.1); border-radius:4px; text-decoration:none;">
            📥 Export Entire Database (SQL)
        </a>
        <span style="color:#4a627a; font-size:12px;">
            Exports all tables with structure and data as .sql file
        </span>
    </div>
    <div style="margin-top:10px; font-size:11px; color:#4a627a;">
        <strong>Info:</strong> This will create a complete SQL dump of database <strong><?= htmlspecialchars($DB_NAME) ?></strong> including all tables, structure, and data.
    </div>
</div>

<!-- SQL Message -->
<?php if ($sql_message): ?>
    <div class="status <?= strpos($sql_message, '✅') !== false ? 'success' : 'error' ?>">
        <?= $sql_message ?>
    </div>
<?php endif; ?>

<!-- Tables -->
<div class="card">
    <h3>📋 Tables (<?= count($tables) ?>) <span class="table-stats">Click any table to view/edit</span></h3>
    
    <div class="table-tabs">
        <?php foreach ($tables as $table): ?>
            <form method="POST" style="display:inline; margin:0;">
                <input type="hidden" name="table_name" value="<?= htmlspecialchars($table) ?>">
                <button type="submit" name="view_table" class="table-btn <?= (isset($selected_table) && $selected_table == $table) ? 'active' : '' ?>">
                    <?= htmlspecialchars($table) ?>
                </button>
            </form>
        <?php endforeach; ?>
    </div>
    
    <?php if (isset($_POST['view_table']) && !empty($table_data)): ?>
        <div class="status info">
            <?= htmlspecialchars($result) ?>
            <button class="copy-btn" onclick="copyTable()">📋 Copy Table</button>
            <a href="?export_csv=1&table=<?= urlencode($selected_table) ?>" class="export-btn-link">📥 Export CSV</a>
            <button class="copy-btn" onclick="showInsertModal()" style="border-color:#2ecc71;color:#2ecc71;">➕ Insert Row</button>
        </div>
        
        <div class="table-wrap">
            <table id="dataTable">
                <thead>
                    <tr>
                        <th style="min-width:40px;">#</th>
                        <?php foreach (array_keys($table_data[0]) as $col): ?>
                            <th><?= htmlspecialchars($col) ?></th>
                        <?php endforeach; ?>
                        <th style="min-width:120px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $row_num = 0; ?>
                    <?php foreach ($table_data as $row): ?>
                        <?php $row_num++; ?>
                        <tr>
                            <td style="color:#4a627a; font-size:11px;"><?= $row_num ?></td>
                            <?php foreach ($row as $key => $value): ?>
                                <td>
                                    <?php 
                                    if (is_null($value)) {
                                        echo '<span style="color:#4a627a; font-style:italic;">NULL</span>';
                                    } elseif (is_numeric($value) && strlen($value) < 20) {
                                        echo htmlspecialchars($value);
                                    } elseif (strlen($value) > 100) {
                                        echo '<span title="' . htmlspecialchars($value) . '">' . htmlspecialchars(substr($value, 0, 100)) . '...</span>';
                                    } else {
                                        echo htmlspecialchars($value);
                                    }
                                    ?>
                                </td>
                            <?php endforeach; ?>
                            <td>
                                <button class="small-btn" style="border-color:#f39c12;color:#f39c12;" onclick="editRow(<?= htmlspecialchars(json_encode($row)) ?>, '<?= htmlspecialchars($selected_table) ?>', <?= htmlspecialchars(json_encode(array_keys($table_data[0]))) ?>)">✏️</button>
                                <button class="small-btn danger" style="border-color:#e74c3c;color:#e74c3c;" onclick="deleteRow('<?= htmlspecialchars($selected_table) ?>', '<?= htmlspecialchars(array_keys($row)[0] ?? 'id') ?>', '<?= htmlspecialchars($row[array_keys($row)[0]] ?? '') ?>')">🗑️</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <div style="margin-top:10px; color:#4a627a; font-size:12px;">
            Total: <?= number_format($total_rows) ?> rows | Showing: <?= number_format(count($table_data)) ?> rows
        </div>
    <?php elseif (isset($_POST['view_table']) && empty($table_data)): ?>
        <div class="status info">Table "<?= htmlspecialchars($selected_table) ?>" is empty (0 rows)</div>
    <?php endif; ?>
</div>

<!-- Query Executor -->
<div class="card">
    <h3>📝 SQL Query Executor (Custom)</h3>
    <form method="POST">
        <textarea name="query" rows="3" placeholder="SELECT * FROM wp_users LIMIT 10; DELETE FROM wp_users WHERE ID=1; UPDATE wp_users SET user_email='new@email.com' WHERE ID=1; DROP TABLE table_name;" style="font-size:13px;"><?= isset($_POST['query']) ? htmlspecialchars($_POST['query']) : '' ?></textarea>
        <button type="submit" name="query_submit" class="warning">▶ Execute Query</button>
    </form>
    <?php if ($result && !isset($_POST['view_table'])): ?>
        <div class="status <?= strpos($result, '❌') !== false ? 'error' : 'info' ?>">
            <?= htmlspecialchars($result) ?>
        </div>
    <?php endif; ?>
</div>

<!-- Query Results (from manual query) -->
<?php if (!empty($query_result) && !isset($_POST['view_table'])): ?>
<div class="card">
    <h3>📊 Query Results</h3>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th style="min-width:40px;">#</th>
                    <?php foreach (array_keys($query_result[0]) as $col): ?>
                        <th><?= htmlspecialchars($col) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php $row_num = 0; ?>
                <?php foreach ($query_result as $row): ?>
                    <?php $row_num++; ?>
                    <tr>
                        <td style="color:#4a627a; font-size:11px;"><?= $row_num ?></td>
                        <?php foreach ($row as $value): ?>
                            <td><?= htmlspecialchars($value ?? 'NULL') ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ============================================================ -->
<!-- INSERT MODAL -->
<!-- ============================================================ -->
<div id="overlay" onclick="closeAllModals()"></div>

<div id="insertModal" class="modal">
    <span class="close-btn" onclick="closeModal('insertModal')">&times;</span>
    <h3>➕ Insert Row into <span id="insertTableName"></span></h3>
    <form method="POST" id="insertForm">
        <input type="hidden" name="table_name" id="insertTableInput" value="">
        <div id="insertFields"></div>
        <div class="edit-actions">
            <button type="submit" name="insert_row" class="success">✅ Insert</button>
            <button type="button" onclick="closeModal('insertModal')">Cancel</button>
        </div>
    </form>
</div>

<!-- ============================================================ -->
<!-- EDIT MODAL -->
<!-- ============================================================ -->
<div id="editModal" class="modal">
    <span class="close-btn" onclick="closeModal('editModal')">&times;</span>
    <h3>✏️ Edit Row in <span id="editTableName"></span></h3>
    <form method="POST" id="editForm">
        <input type="hidden" name="table_name" id="editTableInput" value="">
        <input type="hidden" name="primary_key" id="editPrimaryKey" value="">
        <input type="hidden" name="primary_value" id="editPrimaryValue" value="">
        <div id="editFields"></div>
        <div class="edit-actions">
            <button type="submit" name="update_row" class="warning">💾 Update</button>
            <button type="button" onclick="closeModal('editModal')">Cancel</button>
        </div>
    </form>
</div>

<!-- ============================================================ -->
<!-- DELETE MODAL -->
<!-- ============================================================ -->
<div id="deleteModal" class="modal" style="min-width:400px;border-color:#e74c3c;">
    <span class="close-btn" onclick="closeModal('deleteModal')">&times;</span>
    <h3 style="color:#e74c3c;">🗑️ Confirm Delete</h3>
    <p style="color:#c0d0e0; margin-bottom:15px;">Are you sure you want to delete this row?</p>
    <div id="deleteInfo" style="background:rgba(231,76,60,0.1); padding:10px; border-radius:4px; margin-bottom:15px; font-size:13px;"></div>
    <form method="POST" id="deleteForm">
        <input type="hidden" name="table_name" id="deleteTable" value="">
        <input type="hidden" name="primary_key" id="deleteKey" value="">
        <input type="hidden" name="primary_value" id="deleteValue" value="">
        <div class="edit-actions">
            <button type="submit" name="delete_row" class="danger">🗑️ Confirm Delete</button>
            <button type="button" onclick="closeModal('deleteModal')">Cancel</button>
        </div>
    </form>
</div>

<!-- Quick Queries -->
<div class="card">
    <h3>⚡ Quick Queries</h3>
    <div style="display:flex; flex-wrap:wrap; gap:8px;">
        <form method="POST" style="display:inline;">
            <input type="hidden" name="query" value="SHOW TABLES;">
            <button type="submit" name="query_submit" style="width:auto; font-size:11px; padding:4px 12px;">Show Tables</button>
        </form>
        <form method="POST" style="display:inline;">
            <input type="hidden" name="query" value="SELECT ID, user_login, user_email FROM wp_users LIMIT 10;">
            <button type="submit" name="query_submit" style="width:auto; font-size:11px; padding:4px 12px;">WordPress Users</button>
        </form>
        <form method="POST" style="display:inline;">
            <input type="hidden" name="query" value="SELECT option_name, option_value FROM wp_options WHERE option_name IN ('siteurl', 'home')">
            <button type="submit" name="query_submit" style="width:auto; font-size:11px; padding:4px 12px;">Site URLs</button>
        </form>
        <form method="POST" style="display:inline;">
            <input type="hidden" name="query" value="SELECT COUNT(*) as total FROM wp_users">
            <button type="submit" name="query_submit" style="width:auto; font-size:11px; padding:4px 12px;">User Count</button>
        </form>
    </div>
</div>

<?php endif; ?>

</div>

<script>
// ============================================================
// MODAL FUNCTIONS
// ============================================================
function showModal(id) {
    document.getElementById(id).style.display = 'block';
    document.getElementById('overlay').style.display = 'block';
}

function closeModal(id) {
    document.getElementById(id).style.display = 'none';
    document.getElementById('overlay').style.display = 'none';
}

function closeAllModals() {
    document.querySelectorAll('.modal').forEach(el => el.style.display = 'none');
    document.getElementById('overlay').style.display = 'none';
}

// ============================================================
// INSERT ROW
// ============================================================
function showInsertModal() {
    const table = '<?= addslashes($selected_table) ?>';
    document.getElementById('insertTableName').textContent = table;
    document.getElementById('insertTableInput').value = table;
    
    <?php if (!empty($table_data)): ?>
        const columns = <?= json_encode(array_keys($table_data[0])) ?>;
        let html = '';
        columns.forEach(col => {
            html += `
                <div class="row-edit">
                    <span class="col-label">${col}</span>
                    <input type="text" name="columns[]" value="${col}" readonly style="flex:0.3; color:#4a627a;">
                    <input type="text" name="values[]" placeholder="Value for ${col}" style="flex:0.7;">
                </div>
            `;
        });
        document.getElementById('insertFields').innerHTML = html;
    <?php endif; ?>
    
    showModal('insertModal');
}

// ============================================================
// EDIT ROW
// ============================================================
function editRow(row, table, columns) {
    document.getElementById('editTableName').textContent = table;
    document.getElementById('editTableInput').value = table;
    
    const primaryKey = columns[0];
    document.getElementById('editPrimaryKey').value = primaryKey;
    document.getElementById('editPrimaryValue').value = row[primaryKey];
    
    let html = '';
    columns.forEach(col => {
        const val = row[col] !== null ? row[col] : '';
        html += `
            <div class="row-edit">
                <span class="col-label">${col}</span>
                <input type="text" name="update_columns[]" value="${col}" readonly style="flex:0.3; color:#4a627a;">
                <input type="text" name="update_values[]" value="${val !== null ? val : ''}" placeholder="New value for ${col}" style="flex:0.7;">
            </div>
        `;
    });
    document.getElementById('editFields').innerHTML = html;
    
    showModal('editModal');
}

// ============================================================
// DELETE ROW
// ============================================================
function deleteRow(table, key, value) {
    document.getElementById('deleteTable').value = table;
    document.getElementById('deleteKey').value = key;
    document.getElementById('deleteValue').value = value;
    document.getElementById('deleteInfo').innerHTML = `
        <strong>Table:</strong> ${table}<br>
        <strong>${key}:</strong> ${value}
    `;
    showModal('deleteModal');
}

// ============================================================
// COPY TABLE
// ============================================================
function copyTable() {
    const table = document.getElementById('dataTable');
    if (!table) return;
    
    let text = '';
    const rows = table.querySelectorAll('tr');
    rows.forEach(row => {
        const cells = row.querySelectorAll('td, th');
        const rowData = [];
        cells.forEach(cell => rowData.push(cell.textContent.trim()));
        text += rowData.join('\t') + '\n';
    });
    
    navigator.clipboard.writeText(text).then(() => {
        const btn = document.querySelector('.copy-btn');
        const original = btn.textContent;
        btn.textContent = '✅ Copied!';
        setTimeout(() => btn.textContent = original, 2000);
    });
}

// Close modals on Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeAllModals();
    }
});
</script>

</body>
</html>