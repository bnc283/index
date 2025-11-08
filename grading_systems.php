<?php
require_once '../config/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

Auth::requireRole('admin');

$conn = getDBConnection();
$message = null;
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'settings';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Load standards once for validation
    $standards = [];
    if ($active_tab === 'systems') {
        $std_res = $conn->query("SELECT criteria, min_weight, max_weight FROM grading_criteria_guidelines WHERE is_active = 1");
        if ($std_res) {
            while ($row = $std_res->fetch_assoc()) {
                $standards[strtolower(trim($row['criteria']))] = ['min' => (float)$row['min_weight'], 'max' => (float)$row['max_weight']];
            }
        }
    }

    // Handle settings operations
    if (isset($_POST['create_guideline'])) {
        $criteria = sanitize($_POST['criteria']);
        $description = sanitize($_POST['description']);
        $min_weight = floatval($_POST['min_weight']);
        $max_weight = floatval($_POST['max_weight']);

        $sql = "INSERT INTO grading_criteria_guidelines (criteria, min_weight, max_weight) VALUES (?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sdd", $criteria, $min_weight, $max_weight);
        
        if ($stmt->execute()) {
            $message = ['type' => 'success', 'text' => 'Grading guideline created successfully'];
        } else {
            $message = ['type' => 'error', 'text' => 'Error creating grading guideline'];
        }
    }
    elseif (isset($_POST['update_guideline'])) {
        $guideline_id = intval($_POST['guideline_id']);
        $criteria = sanitize($_POST['criteria']);
        $description = sanitize($_POST['description']);
        $min_weight = floatval($_POST['min_weight']);
        $max_weight = floatval($_POST['max_weight']);

        // table primary key is `id`
        $sql = "UPDATE grading_criteria_guidelines SET criteria = ?, min_weight = ?, max_weight = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sddi", $criteria, $min_weight, $max_weight, $guideline_id);
        
        if ($stmt->execute()) {
            $message = ['type' => 'success', 'text' => 'Grading guideline updated successfully'];
        } else {
            $message = ['type' => 'error', 'text' => 'Error updating grading guideline'];
        }
    }
    elseif (isset($_POST['delete_guideline'])) {
        $guideline_id = intval($_POST['guideline_id']);

        // First, check if the guideline is being used
        // Note: grading_criteria_guidelines primary key is `id`
        $check_sql = "SELECT COUNT(*) as count FROM grading_criteria gc 
                     INNER JOIN grading_systems gs ON gc.grading_system_id = gs.grading_system_id
                     WHERE LOWER(TRIM(gc.component_name)) = (SELECT LOWER(TRIM(criteria)) FROM grading_criteria_guidelines WHERE id = ?)";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("i", $guideline_id);
        $check_stmt->execute();
        $result = $check_stmt->get_result()->fetch_assoc();
        
        if ($result['count'] > 0) {
            $message = ['type' => 'error', 'text' => 'Cannot delete guideline that is being used by existing grading systems'];
        } else {
            $sql = "DELETE FROM grading_criteria_guidelines WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $guideline_id);
            
            if ($stmt->execute()) {
                $message = ['type' => 'success', 'text' => 'Grading guideline deleted successfully'];
            } else {
                $message = ['type' => 'error', 'text' => 'Error deleting grading guideline'];
            }
        }
    }
    elseif (isset($_POST['create_range'])) {
        // Map submitted fields to DB columns: equivalent_grade (decimal), descriptive_rating (string), min_percentage, max_percentage
        $equivalent_grade = floatval($_POST['equivalent_grade'] ?? 0);
        $descriptive_rating = sanitize($_POST['descriptive_rating'] ?? ($_POST['description'] ?? ''));
        $min_percentage = floatval($_POST['min_percentage'] ?? ($_POST['min_range'] ?? 0));
        $max_percentage = floatval($_POST['max_percentage'] ?? ($_POST['max_range'] ?? 0));

        // Check for overlapping ranges (using min_percentage/max_percentage)
        $check_sql = "SELECT COUNT(*) as count FROM grading_transmutation_table 
                     WHERE (? BETWEEN min_percentage AND max_percentage) OR (? BETWEEN min_percentage AND max_percentage)";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("dd", $min_percentage, $max_percentage);
        $check_stmt->execute();
        $result = $check_stmt->get_result()->fetch_assoc();
        
        if ($result['count'] > 0) {
            $message = ['type' => 'error', 'text' => 'The specified range overlaps with an existing range'];
        } else {
            $sql = "INSERT INTO grading_transmutation_table (equivalent_grade, descriptive_rating, min_percentage, max_percentage) VALUES (?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("dsdd", $equivalent_grade, $descriptive_rating, $min_percentage, $max_percentage);
            
            if ($stmt->execute()) {
                $message = ['type' => 'success', 'text' => 'Grade range created successfully'];
            } else {
                $message = ['type' => 'error', 'text' => 'Error creating grade range'];
            }
        }
    }
    elseif (isset($_POST['update_range'])) {
        $range_id = intval($_POST['range_id']);
        $equivalent_grade = floatval($_POST['equivalent_grade'] ?? 0);
        $descriptive_rating = sanitize($_POST['descriptive_rating'] ?? ($_POST['description'] ?? ''));
        $min_percentage = floatval($_POST['min_percentage'] ?? ($_POST['min_range'] ?? 0));
        $max_percentage = floatval($_POST['max_percentage'] ?? ($_POST['max_range'] ?? 0));

        // Check for overlapping ranges (excluding current range)
        $check_sql = "SELECT COUNT(*) as count FROM grading_transmutation_table 
                     WHERE id != ? AND ((? BETWEEN min_percentage AND max_percentage) OR (? BETWEEN min_percentage AND max_percentage))";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("idd", $range_id, $min_percentage, $max_percentage);
        $check_stmt->execute();
        $result = $check_stmt->get_result()->fetch_assoc();
        
        if ($result['count'] > 0) {
            $message = ['type' => 'error', 'text' => 'The specified range overlaps with an existing range'];
        } else {
            $sql = "UPDATE grading_transmutation_table SET equivalent_grade = ?, descriptive_rating = ?, min_percentage = ?, max_percentage = ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("dsddi", $equivalent_grade, $descriptive_rating, $min_percentage, $max_percentage, $range_id);
            
            if ($stmt->execute()) {
                $message = ['type' => 'success', 'text' => 'Grade range updated successfully'];
            } else {
                $message = ['type' => 'error', 'text' => 'Error updating grade range'];
            }
        }
    }
    elseif (isset($_POST['delete_range'])) {
        $range_id = intval($_POST['range_id']);
        
        $sql = "DELETE FROM grading_transmutation_table WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $range_id);
        
        if ($stmt->execute()) {
            $message = ['type' => 'success', 'text' => 'Grade range deleted successfully'];
        } else {
            $message = ['type' => 'error', 'text' => 'Error deleting grade range'];
        }
    }

    if (isset($_POST['create_system'])) {
        $name = sanitize($_POST['name']);
        $description = sanitize($_POST['description']);
        $passing_grade = floatval($_POST['passing_grade']);
        // Use a transaction so criteria + transmutation rows roll back on failure
        $conn->begin_transaction();
        $created_ok = false;
        try {
            // Insert grading system
            $sql = "INSERT INTO grading_systems (name, description, passing_grade, created_by) VALUES (?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssdi", $name, $description, $passing_grade, $_SESSION['user_id']);
            if (!$stmt->execute()) {
                throw new Exception('Error inserting grading system: ' . $stmt->error);
            }
            $grading_system_id = $conn->insert_id;

            // Insert criteria (validate first)
            if (isset($_POST['criteria_names']) && is_array($_POST['criteria_names'])) {
                $total = 0.0;
                foreach ($_POST['criteria_names'] as $index => $criteria_name) {
                    if (!empty($criteria_name)) {
                        $w = (float)($_POST['criteria_weights'][$index] ?? 0);
                        $total += $w;
                        $key = strtolower(trim($criteria_name));
                        if (isset($standards[$key])) {
                            $min = $standards[$key]['min'];
                            $max = $standards[$key]['max'];
                            if ($w < $min || $w > $max) {
                                throw new Exception('Weight for "' . $criteria_name . '" must be between ' . $min . '% and ' . $max . '%.');
                            }
                        }
                    }
                }

                $criteria_to_validate = [];
                foreach ($_POST['criteria_names'] as $index => $criteria_name) {
                    if (!empty($criteria_name)) {
                        $criteria_to_validate[] = [
                            'name' => $criteria_name,
                            'weight' => floatval($_POST['criteria_weights'][$index] ?? 0)
                        ];
                    }
                }
                $validation = validateGradingCriteria($criteria_to_validate);
                if (!$validation['valid']) {
                    throw new Exception(implode("\n", $validation['errors']));
                }

                $criteria_sql = "INSERT INTO grading_criteria (grading_system_id, component_name, weight, description) VALUES (?, ?, ?, ?)";
                $criteria_stmt = $conn->prepare($criteria_sql);
                if (!$criteria_stmt) throw new Exception('Prepare failed for criteria insert: ' . $conn->error);

                foreach ($_POST['criteria_names'] as $index => $criteria_name) {
                    if (!empty($criteria_name)) {
                        $weight = floatval($_POST['criteria_weights'][$index] ?? 0);
                        $criteria_desc = sanitize($_POST['criteria_descriptions'][$index] ?? '');
                        $criteria_stmt->bind_param("isds", $grading_system_id, $criteria_name, $weight, $criteria_desc);
                        if (!$criteria_stmt->execute()) throw new Exception('Error inserting criteria: ' . $criteria_stmt->error);
                    }
                }
            }

            // Handle transmutation rows if any were submitted
            $trans_mins = $_POST['trans_min_percentage'] ?? [];
            $trans_maxs = $_POST['trans_max_percentage'] ?? [];
            $trans_eqs = $_POST['trans_equivalent_grade'] ?? [];
            $trans_descs = $_POST['trans_descriptive_rating'] ?? [];
            $newRanges = [];
            $nTrans = max(count($trans_mins), count($trans_maxs));
            if ($nTrans > 0) {
                // Build new ranges and validate numeric values
                for ($i = 0; $i < $nTrans; $i++) {
                    $min = isset($trans_mins[$i]) ? floatval($trans_mins[$i]) : null;
                    $max = isset($trans_maxs[$i]) ? floatval($trans_maxs[$i]) : null;
                    $eq  = isset($trans_eqs[$i]) ? floatval($trans_eqs[$i]) : null;
                    $desc= isset($trans_descs[$i]) ? sanitize($trans_descs[$i]) : '';
                    if ($min === null || $max === null) continue; // skip incomplete row
                    if ($min > $max) throw new Exception("Transmutation range min ({$min}) cannot be greater than max ({$max}).");
                    $newRanges[] = ['min' => $min, 'max' => $max, 'eq' => $eq, 'desc' => $desc];
                }

                // Check for overlaps among new ranges
                usort($newRanges, function($a, $b){ return $a['min'] <=> $b['min']; });
                for ($i = 1; $i < count($newRanges); $i++) {
                    if ($newRanges[$i]['min'] <= $newRanges[$i-1]['max']) {
                        throw new Exception('Provided transmutation ranges overlap among themselves.');
                    }
                }

                // Prepare check and insert statements
                $check_sql = "SELECT COUNT(*) as count FROM grading_transmutation_table WHERE NOT (max_percentage < ? OR min_percentage > ? )";
                $check_stmt = $conn->prepare($check_sql);
                if (!$check_stmt) throw new Exception('Prepare failed for transmutation check: ' . $conn->error);

                $insert_sql = "INSERT INTO grading_transmutation_table (equivalent_grade, descriptive_rating, min_percentage, max_percentage) VALUES (?, ?, ?, ?)";
                $insert_stmt = $conn->prepare($insert_sql);
                if (!$insert_stmt) throw new Exception('Prepare failed for transmutation insert: ' . $conn->error);

                foreach ($newRanges as $r) {
                    // check overlap with existing DB ranges
                    $check_stmt->bind_param('dd', $r['min'], $r['max']);
                    if (!$check_stmt->execute()) throw new Exception('Error checking transmutation overlap: ' . $check_stmt->error);
                    $res = $check_stmt->get_result()->fetch_assoc();
                    if ($res['count'] > 0) {
                        throw new Exception('One of the provided transmutation ranges overlaps with an existing range');
                    }
                    // insert
                    $insert_stmt->bind_param('dsdd', $r['eq'], $r['desc'], $r['min'], $r['max']);
                    if (!$insert_stmt->execute()) throw new Exception('Error inserting transmutation row: ' . $insert_stmt->error);
                }
            }

            $conn->commit();
            $created_ok = true;
            $message = ['type' => 'success', 'text' => 'Grading system created successfully'];
            $active_tab = 'systems';
        } catch (Exception $e) {
            // Rollback and report error
            $conn->rollback();
            $message = ['type' => 'error', 'text' => 'Error creating grading system: ' . $e->getMessage()];
            // Note: since we used transaction, no manual cleanup needed
        }
    } elseif (isset($_POST['update_system'])) {
        $grading_system_id = intval($_POST['grading_system_id']);
        $name = sanitize($_POST['name']);
        $description = sanitize($_POST['description']);
        $passing_grade = floatval($_POST['passing_grade']);
        
        $sql = "UPDATE grading_systems SET name = ?, description = ?, passing_grade = ? WHERE grading_system_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssdi", $name, $description, $passing_grade, $grading_system_id);
        
        if ($stmt->execute()) {
            // Delete existing criteria
            $delete_sql = "DELETE FROM grading_criteria WHERE grading_system_id = ?";
            $delete_stmt = $conn->prepare($delete_sql);
            $delete_stmt->bind_param("i", $grading_system_id);
            $delete_stmt->execute();
            
            // Insert updated criteria
            if (isset($_POST['criteria_names']) && is_array($_POST['criteria_names'])) {
                // Validate totals and standards
                $total = 0.0;
                foreach ($_POST['criteria_names'] as $index => $criteria_name) {
                    if (!empty($criteria_name)) {
                        $w = (float)$_POST['criteria_weights'][$index];
                        $total += $w;
                        $key = strtolower(trim($criteria_name));
                        if (isset($standards[$key])) {
                            $min = $standards[$key]['min'];
                            $max = $standards[$key]['max'];
                            if ($w < $min || $w > $max) {
                                $message = ['type' => 'error', 'text' => 'Weight for "' . $criteria_name . '" must be between ' . $min . '% and ' . $max . '%.'];
                                break;
                            }
                        }
                    }
                }
                if (!$message && abs($total - 100.0) > 0.001) {
                    $message = ['type' => 'error', 'text' => 'Total weights must equal 100%. Current total: ' . number_format($total, 2) . '%'];
                }
                if (!$message) {
                $criteria_sql = "INSERT INTO grading_criteria (grading_system_id, component_name, weight, description) VALUES (?, ?, ?, ?)";
                $criteria_stmt = $conn->prepare($criteria_sql);
                
                foreach ($_POST['criteria_names'] as $index => $criteria_name) {
                    if (!empty($criteria_name)) {
                        $weight = floatval($_POST['criteria_weights'][$index]);
                        $criteria_desc = sanitize($_POST['criteria_descriptions'][$index] ?? '');
                        $criteria_stmt->bind_param("isds", $grading_system_id, $criteria_name, $weight, $criteria_desc);
                        $criteria_stmt->execute();
                    }
                }
                }
            }
            
            if (!$message) {
                $message = ['type' => 'success', 'text' => 'Grading system updated successfully'];
            }
        } else {
            $message = ['type' => 'error', 'text' => 'Error updating grading system'];
        }
    } elseif (isset($_POST['delete_system'])) {
        $grading_system_id = intval($_POST['grading_system_id']);
        
        // Check if system is in use
        $check_sql = "SELECT COUNT(*) as count FROM classes WHERE grading_system_id = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("i", $grading_system_id);
        $check_stmt->execute();
        $result = $check_stmt->get_result()->fetch_assoc();
        
        if ($result['count'] > 0) {
            $message = ['type' => 'error', 'text' => 'Cannot delete grading system that is in use by classes'];
        } else {
            $sql = "DELETE FROM grading_systems WHERE grading_system_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $grading_system_id);
            
            if ($stmt->execute()) {
                $message = ['type' => 'success', 'text' => 'Grading system deleted successfully'];
            } else {
                $message = ['type' => 'error', 'text' => 'Error deleting grading system'];
            }
        }
    }
}

// Get all grading systems with criteria only when on systems tab
$systems = [];
if ($active_tab === 'systems') {
    $systems_sql = "SELECT 
                    gs.*,
                    CONCAT(u.first_name, ' ', u.last_name) as created_by_name,
                    COUNT(DISTINCT c.class_id) as class_count
                FROM grading_systems gs
                LEFT JOIN users u ON gs.created_by = u.user_id
                LEFT JOIN classes c ON gs.grading_system_id = c.grading_system_id
                GROUP BY gs.grading_system_id
                ORDER BY gs.created_at DESC";
    $systems = $conn->query($systems_sql)->fetch_all(MYSQLI_ASSOC);
}

// Get criteria for each system
foreach ($systems as &$system) {
    $criteria_sql = "SELECT * FROM grading_criteria WHERE grading_system_id = ? ORDER BY criteria_id";
    $stmt = $conn->prepare($criteria_sql);
    $stmt->bind_param("i", $system['grading_system_id']);
    $stmt->execute();
    $system['criteria'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Calculate total weight
    $total_weight = array_sum(array_column($system['criteria'], 'weight'));
    $system['total_weight'] = $total_weight;
    // Compliance check against standards
    $system['compliance'] = [];
    $std_map = [];
    $rs = $conn->query("SELECT criteria, min_weight, max_weight FROM grading_criteria_guidelines WHERE is_active = 1");
    if ($rs) {
        while ($r = $rs->fetch_assoc()) { $std_map[strtolower(trim($r['criteria']))] = $r; }
    }
    foreach ($system['criteria'] as $cr) {
        $key = strtolower(trim($cr['component_name']));
        if (isset($std_map[$key])) {
            $min = (float)$std_map[$key]['min_weight'];
            $max = (float)$std_map[$key]['max_weight'];
            $w = (float)$cr['weight'];
            $system['compliance'][$cr['criteria_id']] = ($w >= $min && $w <= $max);
        } else {
            $system['compliance'][$cr['criteria_id']] = null; // no standard
        }
    }
}

$unread_count = getUnreadNotificationCount($_SESSION['user_id']);

// Load active standards for client-side seeding
$std_rows = [];
$std_sql = "SELECT criteria, min_weight, max_weight FROM grading_criteria_guidelines WHERE is_active = 1 ORDER BY criteria";
$rs = $conn->query($std_sql);
if ($rs) { 
    while ($r = $rs->fetch_assoc()) { 
        $std_rows[] = $r; 
    } 
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Grading System Management - Admin Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-50">
    <?php include 'includes/nav.php'; ?>

    <div class="container mx-auto px-4 py-8">
        <div class="mb-8">
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-3xl font-bold text-gray-900">Grading System Management</h1>
                    <p class="text-gray-600 mt-1">Configure grading systems, criteria guidelines, and grade transmutation</p>
                </div>
                    <?php if ($active_tab === 'settings'): ?>
                <button onclick="openCreateModal()" class="bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-3 rounded-lg text-sm font-semibold transition flex items-center">
                    <i class="fas fa-plus mr-2"></i>Create Grading System
                </button>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="mb-6 p-4 rounded-lg <?php echo $message['type'] === 'success' ? 'bg-green-50 border border-green-200 text-green-700' : 'bg-red-50 border border-red-200 text-red-700'; ?>">
                <i class="fas <?php echo $message['type'] === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?> mr-2"></i>
                <?php echo $message['text']; ?>
            </div>
        <?php endif; ?>
        <script>try{ if(<?php echo json_encode((bool)$message); ?>){ showToast(<?php echo json_encode($message['text'] ?? ''); ?>, <?php echo json_encode($message['type'] ?? 'info'); ?>); } }catch(e){}</script>

        <!-- Tabs -->
        <div class="border-b border-gray-200 mb-6">
            <nav class="-mb-px flex space-x-8">
                <a href="?tab=settings" 
                   class="<?php echo $active_tab === 'settings' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?> whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">
                    <i class="fas fa-cog mr-2"></i>Guidelines & Transmutation
                </a>
                <a href="?tab=systems" 
                   class="<?php echo $active_tab === 'systems' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?> whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">
                    <i class="fas fa-layer-group mr-2"></i>View Grading Systems
                </a>
            </nav>
        </div>

        <!-- Tab Content -->
        <?php if ($active_tab === 'systems'): ?>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <?php if (empty($systems)): ?>
                    <div class="lg:col-span-2 bg-white rounded-lg shadow p-12 text-center">
                    <i class="fas fa-cog text-gray-300 text-6xl mb-4"></i>
                    <h3 class="text-xl font-semibold text-gray-700 mb-2">No Grading Systems</h3>
                        <p class="text-gray-500 mb-4">No grading systems defined yet. Go to Guidelines & Transmutation tab to create one.</p>
                </div>
            <?php else: ?>
                <?php foreach ($systems as $system): ?>
                    <div class="bg-white rounded-lg shadow hover:shadow-lg transition">
                        <div class="p-6 border-b border-gray-200">
                            <div class="flex items-start justify-between">
                                <div class="flex-1">
                                    <h3 class="text-xl font-bold text-gray-900 mb-1">
                                        <?php echo htmlspecialchars($system['name']); ?>
                                    </h3>
                                    <?php if ($system['description']): ?>
                                        <p class="text-sm text-gray-600 mb-2"><?php echo htmlspecialchars($system['description']); ?></p>
                                    <?php endif; ?>
                                    <div class="flex items-center space-x-4 text-sm text-gray-500">
                                        <span><i class="fas fa-user mr-1"></i><?php echo htmlspecialchars($system['created_by_name']); ?></span>
                                        <span><i class="fas fa-calendar mr-1"></i><?php echo formatDate($system['created_at']); ?></span>
                                    </div>
                                </div>
                                <div class="flex space-x-2">
                                    <button onclick='openEditModal(<?php echo json_encode($system); ?>)' class="text-indigo-600 hover:text-indigo-800 p-2">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button onclick="confirmDelete(<?php echo $system['grading_system_id']; ?>, '<?php echo htmlspecialchars($system['name']); ?>', <?php echo $system['class_count']; ?>)" class="text-red-600 hover:text-red-800 p-2">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="p-6">
                            <div class="mb-4">
                                <div class="flex items-center justify-between mb-2">
                                    <span class="text-sm font-semibold text-gray-700">Passing Grade</span>
                                    <span class="text-lg font-bold text-indigo-600"><?php echo $system['passing_grade']; ?>%</span>
                                </div>
                                <div class="flex items-center justify-between mb-2">
                                    <span class="text-sm font-semibold text-gray-700">Total Weight</span>
                                    <span class="text-lg font-bold <?php echo $system['total_weight'] == 100 ? 'text-green-600' : 'text-red-600'; ?>">
                                        <?php echo $system['total_weight']; ?>%
                                        <?php if ($system['total_weight'] != 100): ?>
                                            <i class="fas fa-exclamation-triangle text-sm ml-1" title="Weight should total 100%"></i>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-sm font-semibold text-gray-700">Classes Using</span>
                                    <span class="bg-blue-100 text-blue-800 px-2 py-1 rounded-full text-xs font-semibold">
                                        <?php echo $system['class_count']; ?>
                                    </span>
                                </div>
                            </div>
                            
                            <div class="border-t border-gray-200 pt-4">
                                <h4 class="text-sm font-semibold text-gray-700 mb-3">Grading Components</h4>
                                <div class="space-y-2">
                                    <?php foreach ($system['criteria'] as $criteria): ?>
                                        <div class="flex items-center justify-between p-2 bg-gray-50 rounded">
                                            <div class="flex-1">
                                                <span class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($criteria['component_name']); ?></span>
                                                <?php if ($criteria['description']): ?>
                                                    <p class="text-xs text-gray-500"><?php echo htmlspecialchars($criteria['description']); ?></p>
                                                <?php endif; ?>
                                            </div>
                                            <div class="flex items-center space-x-2 ml-2">
                                                <span class="text-sm font-bold text-indigo-600"><?php echo $criteria['weight']; ?>%</span>
                                                <?php $ok = $system['compliance'][$criteria['criteria_id']] ?? null; ?>
                                                <?php if ($ok === true): ?>
                                                    <span class="px-2 py-0.5 text-xs rounded bg-green-100 text-green-800" title="Within standard">OK</span>
                                                <?php elseif ($ok === false): ?>
                                                    <span class="px-2 py-0.5 text-xs rounded bg-red-100 text-red-800" title="Outside standard">Out</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        </div>
    </div>

    <!-- Create/Edit Modal -->
    <div id="systemModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-10 mx-auto p-5 border w-full max-w-3xl shadow-lg rounded-lg bg-white mb-10">
            <div class="flex justify-between items-center mb-4">
                <h3 id="modalTitle" class="text-2xl font-bold text-gray-900">Create Grading System</h3>
                <button onclick="closeModal('systemModal')" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-2xl"></i>
                </button>
            </div>
            <form id="systemForm" method="POST" action="">
                <input type="hidden" id="grading_system_id" name="grading_system_id">
                
                <div class="mb-4">
                    <label class="block text-gray-700 text-sm font-semibold mb-2" for="name">
                        System Name <span class="text-red-500">*</span>
                    </label>
                    <input type="text" id="name" name="name" required
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500"
                        placeholder="e.g., Standard Grading System">
                </div>

                <div class="mb-4">
                    <label class="block text-gray-700 text-sm font-semibold mb-2" for="description">
                        Description
                    </label>
                    <textarea id="description" name="description" rows="2"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500"
                        placeholder="Brief description of this grading system..."></textarea>
                </div>

                <div class="mb-6">
                    <label class="block text-gray-700 text-sm font-semibold mb-2" for="passing_grade">
                        Passing Grade (%) <span class="text-red-500">*</span>
                    </label>
                    <input type="number" id="passing_grade" name="passing_grade" required min="0" max="100" step="0.01" value="60"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                </div>

                <div class="mb-4">
                    <div class="flex items-center justify-between mb-2">
                        <label class="block text-gray-700 text-sm font-semibold">
                            Grading Components <span class="text-red-500">*</span>
                        </label>
                        <button type="button" onclick="addCriteria()" class="text-indigo-600 hover:text-indigo-800 text-sm font-semibold">
                            <i class="fas fa-plus mr-1"></i>Add Component
                        </button>
                    </div>
                    <div id="criteriaContainer" class="space-y-3">
                        <!-- Criteria will be added here -->
                    </div>
                    <p class="text-sm text-gray-500 mt-2">Total weight should equal 100%</p>
                </div>

                <!-- Grade Transmutation (optional during create) -->
                <div class="mb-4">
                    <div class="flex items-center justify-between mb-2">
                        <label class="block text-gray-700 text-sm font-semibold">Grade Transmutation (optional)</label>
                        <button type="button" onclick="addTransmutationRow()" class="text-indigo-600 hover:text-indigo-800 text-sm font-semibold">
                            <i class="fas fa-plus mr-1"></i>Add Range
                        </button>
                    </div>
                    <div id="transmutationContainer" class="space-y-3">
                        <!-- Transmutation rows will be added here -->
                    </div>
                    <p class="text-sm text-gray-500 mt-2">Define % ranges and equivalent grade (e.g., 90-100 -> 1.00)</p>
                </div>

                <div class="flex justify-end space-x-3 pt-4 border-t border-gray-200">
                    <button type="button" onclick="closeModal('systemModal')" class="px-6 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition">
                        Cancel
                    </button>
                    <button type="submit" id="submitBtn" name="create_system" class="px-6 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition">
                        <i class="fas fa-save mr-2"></i>Create System
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-lg bg-white">
            <div class="text-center">
                <i class="fas fa-exclamation-triangle text-red-500 text-5xl mb-4"></i>
                <h3 class="text-xl font-bold text-gray-900 mb-2">Delete Grading System</h3>
                <p class="text-gray-600 mb-6" id="deleteMessage"></p>
                <form method="POST" action="">
                    <input type="hidden" id="delete_system_id" name="grading_system_id">
                    <div class="flex justify-center space-x-3">
                        <button type="button" onclick="closeModal('deleteModal')" class="px-6 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition">
                            Cancel
                        </button>
                        <button type="submit" name="delete_system" id="deleteBtn" class="px-6 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition">
                            <i class="fas fa-trash mr-2"></i>Delete
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php 
        // Include the appropriate tab content
        if ($active_tab === 'settings') {
            include 'tabs/settings_content.php';
            include 'modals/settings_modals.php';
            ?><script src="../js/settings.js"></script><?php
        }
        ?>
        <script>
            // Expose active grading standards to client for seeding criteria
            const STANDARDS = <?php echo json_encode($std_rows); ?> || [];
        </script>
        <script src="../js/systems.js"></script>
        <script src="../includes/ui.js"></script>
    </div>
<?php
// Close DB connection now that all tab content (which may use $conn) has been included
if (isset($conn)) {
    closeDBConnection($conn);
}
?>
</body>
</html>