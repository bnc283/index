<?php
require_once '../config/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

Auth::requireRole('admin');

$conn = getDBConnection();
$message = null;

// Handle Criteria Guidelines Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_criterion'])) {
        $criteria = sanitize($_POST['criteria']);
        $min_weight = floatval($_POST['min_weight']);
        $max_weight = floatval($_POST['max_weight']);
        
        if ($min_weight <= $max_weight && $min_weight >= 0 && $max_weight <= 100) {
            $sql = "INSERT INTO grading_criteria_guidelines (criteria, min_weight, max_weight, is_active) 
                    VALUES (?, ?, ?, 1)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sdd", $criteria, $min_weight, $max_weight);
            
            if ($stmt->execute()) {
                $message = ['type' => 'success', 'text' => 'Criterion added successfully'];
            } else {
                $message = ['type' => 'error', 'text' => 'Error adding criterion'];
            }
        } else {
            $message = ['type' => 'error', 'text' => 'Invalid weight range'];
        }
    }
    elseif (isset($_POST['edit_criterion'])) {
        $id = intval($_POST['criterion_id']);
        $criteria = sanitize($_POST['criteria']);
        $min_weight = floatval($_POST['min_weight']);
        $max_weight = floatval($_POST['max_weight']);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        if ($min_weight <= $max_weight && $min_weight >= 0 && $max_weight <= 100) {
            $sql = "UPDATE grading_criteria_guidelines 
                    SET criteria = ?, min_weight = ?, max_weight = ?, is_active = ? 
                    WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sddii", $criteria, $min_weight, $max_weight, $is_active, $id);
            
            if ($stmt->execute()) {
                $message = ['type' => 'success', 'text' => 'Criterion updated successfully'];
            } else {
                $message = ['type' => 'error', 'text' => 'Error updating criterion'];
            }
        } else {
            $message = ['type' => 'error', 'text' => 'Invalid weight range'];
        }
    }
    elseif (isset($_POST['delete_criterion'])) {
        $id = intval($_POST['criterion_id']);
        $sql = "DELETE FROM grading_criteria_guidelines WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            $message = ['type' => 'success', 'text' => 'Criterion deleted successfully'];
        } else {
            $message = ['type' => 'error', 'text' => 'Error deleting criterion'];
        }
    }
    elseif (isset($_POST['add_transmutation'])) {
        $min = floatval($_POST['min_percentage']);
        $max = floatval($_POST['max_percentage']);
        $equivalent = floatval($_POST['equivalent_grade']);
        $description = sanitize($_POST['description']);
        
        if ($min <= $max && $min >= 0 && $max <= 100) {
            $sql = "INSERT INTO grading_transmutation_table (min_percentage, max_percentage, equivalent_grade, descriptive_rating) 
                    VALUES (?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ddds", $min, $max, $equivalent, $description);
            
            if ($stmt->execute()) {
                $message = ['type' => 'success', 'text' => 'Transmutation row added successfully'];
            } else {
                $message = ['type' => 'error', 'text' => 'Error adding transmutation row'];
            }
        } else {
            $message = ['type' => 'error', 'text' => 'Invalid percentage range'];
        }
    }
    elseif (isset($_POST['edit_transmutation'])) {
        $id = intval($_POST['transmutation_id']);
        $min = floatval($_POST['min_percentage']);
        $max = floatval($_POST['max_percentage']);
        $equivalent = floatval($_POST['equivalent_grade']);
        $description = sanitize($_POST['description']);
        
        if ($min <= $max && $min >= 0 && $max <= 100) {
            $sql = "UPDATE grading_transmutation_table 
                    SET min_percentage = ?, max_percentage = ?, equivalent_grade = ?, descriptive_rating = ? 
                    WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("dddsi", $min, $max, $equivalent, $description, $id);
            
            if ($stmt->execute()) {
                $message = ['type' => 'success', 'text' => 'Transmutation row updated successfully'];
            } else {
                $message = ['type' => 'error', 'text' => 'Error updating transmutation row'];
            }
        } else {
            $message = ['type' => 'error', 'text' => 'Invalid percentage range'];
        }
    }
    elseif (isset($_POST['delete_transmutation'])) {
        $id = intval($_POST['transmutation_id']);
        $sql = "DELETE FROM grading_transmutation_table WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            $message = ['type' => 'success', 'text' => 'Transmutation row deleted successfully'];
        } else {
            $message = ['type' => 'error', 'text' => 'Error deleting transmutation row'];
        }
    }
}

// Get Criteria Guidelines
$criteria_sql = "SELECT * FROM grading_criteria_guidelines ORDER BY criteria";
$criteria_guidelines = $conn->query($criteria_sql)->fetch_all(MYSQLI_ASSOC);

// Get Transmutation Table
$transmutation_sql = "SELECT * FROM grading_transmutation_table ORDER BY min_percentage DESC";
$transmutation_table = $conn->query($transmutation_sql)->fetch_all(MYSQLI_ASSOC);

$unread_count = getUnreadNotificationCount($_SESSION['user_id']);

closeDBConnection($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Grading Settings - Admin Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-50">
    <?php include 'includes/nav.php'; ?>

    <div class="container mx-auto px-4 py-8">
        <div class="mb-8">
            <h1 class="text-3xl font-bold text-gray-900">Grading Settings</h1>
            <p class="text-gray-600 mt-1">Manage grading criteria guidelines and grade transmutation</p>
        </div>

        <?php if ($message): ?>
            <div class="mb-6 p-4 rounded-lg <?php echo $message['type'] === 'success' ? 'bg-green-50 border border-green-200 text-green-700' : 'bg-red-50 border border-red-200 text-red-700'; ?>">
                <i class="fas <?php echo $message['type'] === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?> mr-2"></i>
                <?php echo $message['text']; ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <!-- Criteria Weight Guidelines -->
            <div class="bg-white rounded-lg shadow-lg p-6">
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h2 class="text-xl font-bold text-gray-900 flex items-center">
                            <i class="fas fa-balance-scale mr-2"></i>
                            Criteria Weight Guidelines
                        </h2>
                        <p class="text-gray-600 text-sm mt-1">Define acceptable weight ranges for grading components</p>
                    </div>
                    <button onclick="openCriterionModal()" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-semibold transition">
                        <i class="fas fa-plus mr-1"></i>Add Criterion
                    </button>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-gray-50">
                                <th class="px-4 py-2 text-left text-gray-700">CRITERIA</th>
                                <th class="px-4 py-2 text-center text-gray-700">MIN %</th>
                                <th class="px-4 py-2 text-center text-gray-700">MAX %</th>
                                <th class="px-4 py-2 text-center text-gray-700">ACTIVE</th>
                                <th class="px-4 py-2 text-right text-gray-700">ACTIONS</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php foreach ($criteria_guidelines as $criterion): ?>
                                <tr>
                                    <td class="px-4 py-3"><?php echo htmlspecialchars($criterion['criteria']); ?></td>
                                    <td class="px-4 py-3 text-center"><?php echo number_format($criterion['min_weight'], 2); ?></td>
                                    <td class="px-4 py-3 text-center"><?php echo number_format($criterion['max_weight'], 2); ?></td>
                                    <td class="px-4 py-3 text-center">
                                        <span class="inline-block w-3 h-3 rounded-full <?php echo $criterion['is_active'] ? 'bg-green-500' : 'bg-gray-300'; ?>"></span>
                                    </td>
                                    <td class="px-4 py-3 text-right space-x-2">
                                        <button onclick='openCriterionModal(<?php echo json_encode($criterion); ?>)' class="text-indigo-600 hover:text-indigo-800">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button onclick="confirmDeleteCriterion(<?php echo $criterion['id']; ?>, '<?php echo htmlspecialchars($criterion['criteria']); ?>')" class="text-red-600 hover:text-red-800">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Transmutation Table -->
            <div class="bg-white rounded-lg shadow-lg p-6">
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h2 class="text-xl font-bold text-gray-900 flex items-center">
                            <i class="fas fa-exchange-alt mr-2"></i>
                            Transmutation Table
                        </h2>
                        <p class="text-gray-600 text-sm mt-1">Define grade equivalents and descriptive ratings</p>
                    </div>
                    <button onclick="openTransmutationModal()" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-semibold transition">
                        <i class="fas fa-plus mr-1"></i>Add Row
                    </button>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-gray-50">
                                <th class="px-4 py-2 text-center text-gray-700">MIN %</th>
                                <th class="px-4 py-2 text-center text-gray-700">MAX %</th>
                                <th class="px-4 py-2 text-center text-gray-700">EQUIVALENT</th>
                                <th class="px-4 py-2 text-left text-gray-700">DESCRIPTION</th>
                                <th class="px-4 py-2 text-right text-gray-700">ACTIONS</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php foreach ($transmutation_table as $row): ?>
                                <tr>
                                    <td class="px-4 py-3 text-center"><?php echo number_format($row['min_percentage'], 2); ?></td>
                                    <td class="px-4 py-3 text-center"><?php echo number_format($row['max_percentage'], 2); ?></td>
                                    <td class="px-4 py-3 text-center font-medium"><?php echo number_format($row['equivalent_grade'], 2); ?></td>
                                    <td class="px-4 py-3"><?php echo htmlspecialchars($row['descriptive_rating']); ?></td>
                                    <td class="px-4 py-3 text-right space-x-2">
                                        <button onclick='openTransmutationModal(<?php echo json_encode($row); ?>)' class="text-indigo-600 hover:text-indigo-800">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button onclick="confirmDeleteTransmutation(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars($row['descriptive_rating']); ?>')" class="text-red-600 hover:text-red-800">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Criterion Modal -->
    <div id="criterionModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-lg bg-white">
            <div class="flex justify-between items-center mb-4">
                <h3 id="criterionModalTitle" class="text-xl font-bold text-gray-900">Add Criterion</h3>
                <button onclick="closeCriterionModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="criterionForm" method="POST" action="">
                <input type="hidden" id="criterion_id" name="criterion_id">
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Criterion Name</label>
                        <input type="text" id="criteria" name="criteria" required
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500"
                            placeholder="e.g., Midterm Exam">
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Minimum Weight (%)</label>
                            <input type="number" id="min_weight" name="min_weight" required min="0" max="100" step="0.01"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                placeholder="0.00">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Maximum Weight (%)</label>
                            <input type="number" id="max_weight" name="max_weight" required min="0" max="100" step="0.01"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                placeholder="100.00">
                        </div>
                    </div>
                    <div class="flex items-center">
                        <input type="checkbox" id="is_active" name="is_active" class="h-4 w-4 text-indigo-600 rounded border-gray-300" checked>
                        <label class="ml-2 text-sm text-gray-700">Active</label>
                    </div>
                </div>
                <div class="mt-6 flex justify-end space-x-3">
                    <button type="button" onclick="closeCriterionModal()" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition">
                        Cancel
                    </button>
                    <button type="submit" id="criterionSubmitBtn" name="add_criterion" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition">
                        <i class="fas fa-save mr-2"></i>Save Criterion
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Transmutation Modal -->
    <div id="transmutationModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-lg bg-white">
            <div class="flex justify-between items-center mb-4">
                <h3 id="transmutationModalTitle" class="text-xl font-bold text-gray-900">Add Grade Range</h3>
                <button onclick="closeTransmutationModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="transmutationForm" method="POST" action="">
                <input type="hidden" id="transmutation_id" name="transmutation_id">
                <div class="space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Min Percentage</label>
                            <input type="number" id="min_percentage" name="min_percentage" required min="0" max="100" step="0.01"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                placeholder="0.00">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Max Percentage</label>
                            <input type="number" id="max_percentage" name="max_percentage" required min="0" max="100" step="0.01"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                placeholder="100.00">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Equivalent Grade</label>
                        <input type="number" id="equivalent_grade" name="equivalent_grade" required min="1" max="5" step="0.01"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500"
                            placeholder="1.00">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                        <input type="text" id="description" name="description" required
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500"
                            placeholder="e.g., Excellent">
                    </div>
                </div>
                <div class="mt-6 flex justify-end space-x-3">
                    <button type="button" onclick="closeTransmutationModal()" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition">
                        Cancel
                    </button>
                    <button type="submit" id="transmutationSubmitBtn" name="add_transmutation" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition">
                        <i class="fas fa-save mr-2"></i>Save Range
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
                <h3 class="text-xl font-bold text-gray-900 mb-2">Confirm Delete</h3>
                <p class="text-gray-600 mb-6" id="deleteMessage"></p>
                <form method="POST" action="">
                    <input type="hidden" id="delete_criterion_id" name="criterion_id">
                    <input type="hidden" id="delete_transmutation_id" name="transmutation_id">
                    <div class="flex justify-center space-x-3">
                        <button type="button" onclick="closeDeleteModal()" class="px-6 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition">
                            Cancel
                        </button>
                        <button type="submit" id="deleteBtn" name="delete_criterion" class="px-6 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition">
                            <i class="fas fa-trash mr-2"></i>Delete
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function openCriterionModal(criterion = null) {
            const modal = document.getElementById('criterionModal');
            const form = document.getElementById('criterionForm');
            const title = document.getElementById('criterionModalTitle');
            const submitBtn = document.getElementById('criterionSubmitBtn');
            
            if (criterion) {
                title.textContent = 'Edit Criterion';
                document.getElementById('criterion_id').value = criterion.id;
                document.getElementById('criteria').value = criterion.criteria;
                document.getElementById('min_weight').value = criterion.min_weight;
                document.getElementById('max_weight').value = criterion.max_weight;
                document.getElementById('is_active').checked = criterion.is_active == 1;
                submitBtn.name = 'edit_criterion';
                submitBtn.innerHTML = '<i class="fas fa-save mr-2"></i>Update Criterion';
            } else {
                title.textContent = 'Add Criterion';
                form.reset();
                document.getElementById('criterion_id').value = '';
                document.getElementById('is_active').checked = true;
                submitBtn.name = 'add_criterion';
                submitBtn.innerHTML = '<i class="fas fa-save mr-2"></i>Save Criterion';
            }
            
            modal.classList.remove('hidden');
        }

        function closeCriterionModal() {
            document.getElementById('criterionModal').classList.add('hidden');
        }

        function openTransmutationModal(row = null) {
            const modal = document.getElementById('transmutationModal');
            const form = document.getElementById('transmutationForm');
            const title = document.getElementById('transmutationModalTitle');
            const submitBtn = document.getElementById('transmutationSubmitBtn');
            
            if (row) {
                title.textContent = 'Edit Grade Range';
                document.getElementById('transmutation_id').value = row.id;
                document.getElementById('min_percentage').value = row.min_percentage;
                document.getElementById('max_percentage').value = row.max_percentage;
                document.getElementById('equivalent_grade').value = row.equivalent_grade;
                document.getElementById('description').value = row.descriptive_rating;
                submitBtn.name = 'edit_transmutation';
                submitBtn.innerHTML = '<i class="fas fa-save mr-2"></i>Update Range';
            } else {
                title.textContent = 'Add Grade Range';
                form.reset();
                document.getElementById('transmutation_id').value = '';
                submitBtn.name = 'add_transmutation';
                submitBtn.innerHTML = '<i class="fas fa-save mr-2"></i>Save Range';
            }
            
            modal.classList.remove('hidden');
        }

        function closeTransmutationModal() {
            document.getElementById('transmutationModal').classList.add('hidden');
        }

        function confirmDeleteCriterion(id, name) {
            document.getElementById('deleteMessage').textContent = `Are you sure you want to delete "${name}"?`;
            document.getElementById('delete_criterion_id').value = id;
            document.getElementById('delete_transmutation_id').value = '';
            document.getElementById('deleteBtn').name = 'delete_criterion';
            document.getElementById('deleteModal').classList.remove('hidden');
        }

        function confirmDeleteTransmutation(id, description) {
            document.getElementById('deleteMessage').textContent = `Are you sure you want to delete the grade range for "${description}"?`;
            document.getElementById('delete_criterion_id').value = '';
            document.getElementById('delete_transmutation_id').value = id;
            document.getElementById('deleteBtn').name = 'delete_transmutation';
            document.getElementById('deleteModal').classList.remove('hidden');
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').classList.add('hidden');
        }

        // Close modals on outside click
        window.onclick = function(event) {
            const criterionModal = document.getElementById('criterionModal');
            const transmutationModal = document.getElementById('transmutationModal');
            const deleteModal = document.getElementById('deleteModal');
            
            if (event.target === criterionModal) {
                closeCriterionModal();
            }
            if (event.target === transmutationModal) {
                closeTransmutationModal();
            }
            if (event.target === deleteModal) {
                closeDeleteModal();
            }
        }
    </script>
</body>
</html>