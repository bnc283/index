<?php
require_once '../config/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

Auth::requireRole('admin');

$conn = getDBConnection();

// Handle user actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_user'])) {
        $result = Auth::register($_POST);
        $message = $result['success'] ? ['type' => 'success', 'text' => $result['message']] : ['type' => 'error', 'text' => $result['message']];
    } elseif (isset($_POST['toggle_status'])) {
        $user_id = intval($_POST['user_id']);
        $new_status = $_POST['new_status'];
        $sql = "UPDATE users SET status = ? WHERE user_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("si", $new_status, $user_id);
        $stmt->execute();
        $message = ['type' => 'success', 'text' => 'User status updated'];
    }
}

// Get all users
$users_sql = "SELECT u.*, s.student_number, s.program, i.employee_number, i.department
              FROM users u
              LEFT JOIN students s ON u.user_id = s.user_id
              LEFT JOIN instructors i ON u.user_id = i.user_id
              ORDER BY u.created_at DESC";
$users = $conn->query($users_sql)->fetch_all(MYSQLI_ASSOC);

$unread_count = getUnreadNotificationCount($_SESSION['user_id']);
closeDBConnection($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users - Admin Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-50">
    <?php include 'includes/nav.php'; ?>

    <div class="container mx-auto px-4 py-8">
        <div class="mb-8 flex justify-between items-center">
            <h1 class="text-3xl font-bold text-gray-900">Manage Users</h1>
            <button onclick="document.getElementById('createModal').classList.remove('hidden')" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg">
                <i class="fas fa-plus mr-2"></i>Create User
            </button>
        </div>

        <?php if (isset($message)): ?>
            <div class="bg-<?php echo $message['type'] === 'success' ? 'green' : 'red'; ?>-50 border border-<?php echo $message['type'] === 'success' ? 'green' : 'red'; ?>-200 text-<?php echo $message['type'] === 'success' ? 'green' : 'red'; ?>-700 px-4 py-3 rounded mb-6">
                <?php echo htmlspecialchars($message['text']); ?>
            </div>
        <?php endif; ?>

        <div class="bg-white rounded-lg shadow overflow-hidden">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Email</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Role</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach ($users as $user): ?>
                    <tr>
                        <td class="px-6 py-4">
                            <p class="font-medium text-gray-900"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></p>
                            <p class="text-sm text-gray-500">
                                <?php echo $user['student_number'] ?? $user['employee_number'] ?? 'N/A'; ?>
                            </p>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-900"><?php echo htmlspecialchars($user['email']); ?></td>
                        <td class="px-6 py-4">
                            <span class="px-3 py-1 rounded-full text-xs font-semibold <?php echo getStatusBadgeClass($user['role']); ?>">
                                <?php echo ucfirst($user['role']); ?>
                            </span>
                        </td>
                        <td class="px-6 py-4">
                            <span class="px-3 py-1 rounded-full text-xs font-semibold <?php echo getStatusBadgeClass($user['status']); ?>">
                                <?php echo ucfirst($user['status']); ?>
                            </span>
                        </td>
                        <td class="px-6 py-4">
                            <form method="POST" class="inline">
                                <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                                <input type="hidden" name="new_status" value="<?php echo $user['status'] === 'active' ? 'inactive' : 'active'; ?>">
                                <button type="submit" name="toggle_status" class="text-indigo-600 hover:text-indigo-900 text-sm">
                                    <?php echo $user['status'] === 'active' ? 'Deactivate' : 'Activate'; ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Create User Modal -->
    <div id="createModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
        <div class="bg-white rounded-lg p-8 max-w-2xl w-full max-h-screen overflow-y-auto">
            <h2 class="text-2xl font-bold mb-4">Create New User</h2>
            <form method="POST" id="createUserForm">
                <div class="grid grid-cols-2 gap-4 mb-4">
                    <input type="text" name="first_name" placeholder="First Name" required class="px-4 py-2 border rounded-lg">
                    <input type="text" name="last_name" placeholder="Last Name" required class="px-4 py-2 border rounded-lg">
                </div>
                <input type="email" name="email" placeholder="Email" required class="w-full px-4 py-2 border rounded-lg mb-4">
                <select name="role" id="roleSelect" required class="w-full px-4 py-2 border rounded-lg mb-4" onchange="toggleRoleFields()">
                    <option value="">Select Role</option>
                    <option value="student">Student</option>
                    <option value="instructor">Instructor</option>
                    <option value="admin">Admin</option>
                </select>
                
                <!-- Student-specific fields -->
                <div id="studentFields" class="hidden mb-4">
                    <div class="bg-blue-50 p-4 rounded-lg space-y-3">
                        <h3 class="font-semibold text-gray-700">Student Information</h3>
                        <input type="text" name="student_number" placeholder="Student Number" class="w-full px-4 py-2 border rounded-lg">
                        <input type="text" name="program" placeholder="Program (e.g., BS Computer Science)" class="w-full px-4 py-2 border rounded-lg">
                        <div class="grid grid-cols-2 gap-3">
                            <input type="number" name="year_level" placeholder="Year Level" min="1" max="5" class="px-4 py-2 border rounded-lg">
                            <input type="number" name="enrollment_year" placeholder="Enrollment Year" min="2000" max="2100" value="<?php echo date('Y'); ?>" class="px-4 py-2 border rounded-lg">
                        </div>
                    </div>
                </div>
                
                <!-- Instructor-specific fields -->
                <div id="instructorFields" class="hidden mb-4">
                    <div class="bg-green-50 p-4 rounded-lg space-y-3">
                        <h3 class="font-semibold text-gray-700">Instructor Information</h3>
                        <div>
                            <label class="block text-gray-700 text-sm font-semibold mb-2">Employee Number</label>
                            <input type="text" name="employee_number" placeholder="Employee Number" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-gray-700 text-sm font-semibold mb-2">Department</label>
                                <input type="text" name="department" placeholder="Department" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                            </div>
                            <div>
                                <label class="block text-gray-700 text-sm font-semibold mb-2">Specialization</label>
                                <input type="text" name="specialization" placeholder="Specialization" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="grid grid-cols-2 gap-4 mb-4">
                    <input type="password" name="password" placeholder="Password" required class="px-4 py-2 border rounded-lg">
                    <input type="password" name="confirm_password" placeholder="Confirm" required class="px-4 py-2 border rounded-lg">
                </div>
                <div class="flex justify-end space-x-2">
                    <button type="button" onclick="closeCreateModal()" class="px-4 py-2 bg-gray-300 rounded-lg">Cancel</button>
                    <button type="submit" name="create_user" class="px-4 py-2 bg-indigo-600 text-white rounded-lg">Create</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function toggleRoleFields() {
            const role = document.getElementById('roleSelect').value;
            const studentFields = document.getElementById('studentFields');
            const instructorFields = document.getElementById('instructorFields');
            
            // Hide all role-specific fields
            studentFields.classList.add('hidden');
            instructorFields.classList.add('hidden');
            
            // Remove required attribute from all role-specific inputs
            studentFields.querySelectorAll('input').forEach(input => input.removeAttribute('required'));
            instructorFields.querySelectorAll('input').forEach(input => input.removeAttribute('required'));
            
            // Show and set required for selected role
            if (role === 'student') {
                studentFields.classList.remove('hidden');
                studentFields.querySelector('[name="student_number"]').setAttribute('required', 'required');
                studentFields.querySelector('[name="program"]').setAttribute('required', 'required');
                studentFields.querySelector('[name="year_level"]').setAttribute('required', 'required');
                studentFields.querySelector('[name="enrollment_year"]').setAttribute('required', 'required');
            } else if (role === 'instructor') {
                instructorFields.classList.remove('hidden');
                instructorFields.querySelector('[name="employee_number"]').setAttribute('required', 'required');
                instructorFields.querySelector('[name="department"]').setAttribute('required', 'required');
                instructorFields.querySelector('[name="specialization"]').setAttribute('required', 'required');
            }
        }
        
        function closeCreateModal() {
            document.getElementById('createModal').classList.add('hidden');
            document.getElementById('createUserForm').reset();
            toggleRoleFields();
        }
    </script>
</body>
</html>
