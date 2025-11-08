<?php
require_once '../config/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

Auth::requireRole('admin');

$conn = getDBConnection();

$message = null;

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_profile'])) {
        $first_name = sanitize($_POST['first_name']);
        $last_name = sanitize($_POST['last_name']);
        $email = sanitize($_POST['email']);
        
        // Check if email is already taken by another user
        $check_sql = "SELECT user_id FROM users WHERE email = ? AND user_id != ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("si", $email, $_SESSION['user_id']);
        $check_stmt->execute();
        $result = $check_stmt->get_result();
        
        if ($result->num_rows > 0) {
            $message = ['type' => 'error', 'text' => 'Email is already in use by another user'];
        } else {
            $sql = "UPDATE users SET first_name = ?, last_name = ?, email = ? WHERE user_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sssi", $first_name, $last_name, $email, $_SESSION['user_id']);
            
            if ($stmt->execute()) {
                $_SESSION['first_name'] = $first_name;
                $_SESSION['last_name'] = $last_name;
                $_SESSION['email'] = $email;
                $message = ['type' => 'success', 'text' => 'Profile updated successfully'];
            } else {
                $message = ['type' => 'error', 'text' => 'Error updating profile'];
            }
        }
    } elseif (isset($_POST['change_password'])) {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        // Get current password hash
        $sql = "SELECT password_hash FROM users WHERE user_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        
        if (!password_verify($current_password, $user['password_hash'])) {
            $message = ['type' => 'error', 'text' => 'Current password is incorrect'];
        } elseif ($new_password !== $confirm_password) {
            $message = ['type' => 'error', 'text' => 'New passwords do not match'];
        } elseif (strlen($new_password) < 6) {
            $message = ['type' => 'error', 'text' => 'Password must be at least 6 characters'];
        } else {
            $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $update_sql = "UPDATE users SET password_hash = ? WHERE user_id = ?";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param("si", $new_hash, $_SESSION['user_id']);
            
            if ($update_stmt->execute()) {
                $message = ['type' => 'success', 'text' => 'Password changed successfully'];
            } else {
                $message = ['type' => 'error', 'text' => 'Error changing password'];
            }
        }
    }
}

// Get user data
$sql = "SELECT * FROM users WHERE user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

// Get activity stats
$activity_stats = [
    'total_logins' => $conn->query("SELECT COUNT(*) as count FROM activity_logs WHERE user_id = {$_SESSION['user_id']} AND action = 'login'")->fetch_assoc()['count'],
    'last_login' => $user['last_login'] ? formatDateTime($user['last_login']) : 'Never',
    'account_age' => floor((time() - strtotime($user['created_at'])) / 86400) . ' days'
];

$unread_count = getUnreadNotificationCount($_SESSION['user_id']);
closeDBConnection($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - Admin Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-50">
    <?php include 'includes/nav.php'; ?>

    <div class="container mx-auto px-4 py-8">
        <div class="mb-8">
            <h1 class="text-3xl font-bold text-gray-900">My Profile</h1>
            <p class="text-gray-600 mt-1">Manage your account settings and preferences</p>
        </div>

        <?php if ($message): ?>
            <div class="mb-6 p-4 rounded-lg <?php echo $message['type'] === 'success' ? 'bg-green-50 border border-green-200 text-green-700' : 'bg-red-50 border border-red-200 text-red-700'; ?>">
                <i class="fas <?php echo $message['type'] === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?> mr-2"></i>
                <?php echo $message['text']; ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Profile Summary -->
            <div class="lg:col-span-1">
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="text-center mb-6">
                        <div class="inline-flex items-center justify-center w-24 h-24 bg-indigo-600 rounded-full mb-4">
                            <span class="text-white text-3xl font-bold">
                                <?php echo strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)); ?>
                            </span>
                        </div>
                        <h2 class="text-2xl font-bold text-gray-900">
                            <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
                        </h2>
                        <p class="text-gray-600"><?php echo htmlspecialchars($user['email']); ?></p>
                        <span class="inline-block mt-2 px-3 py-1 bg-red-100 text-red-800 rounded-full text-sm font-semibold">
                            <i class="fas fa-user-shield mr-1"></i>Administrator
                        </span>
                    </div>

                    <div class="border-t border-gray-200 pt-6 space-y-4">
                        <div class="flex items-center justify-between">
                            <span class="text-gray-600">Status</span>
                            <span class="px-3 py-1 bg-green-100 text-green-800 rounded-full text-sm font-semibold">
                                <?php echo ucfirst($user['status']); ?>
                            </span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-gray-600">Member Since</span>
                            <span class="text-gray-900 font-medium"><?php echo formatDate($user['created_at']); ?></span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-gray-600">Account Age</span>
                            <span class="text-gray-900 font-medium"><?php echo $activity_stats['account_age']; ?></span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-gray-600">Total Logins</span>
                            <span class="text-gray-900 font-medium"><?php echo $activity_stats['total_logins']; ?></span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-gray-600">Last Login</span>
                            <span class="text-gray-900 font-medium text-sm"><?php echo $activity_stats['last_login']; ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Profile Forms -->
            <div class="lg:col-span-2 space-y-6">
                <!-- Update Profile Information -->
                <div class="bg-white rounded-lg shadow">
                    <div class="p-6 border-b border-gray-200">
                        <h3 class="text-xl font-bold text-gray-900">
                            <i class="fas fa-user-edit text-indigo-600 mr-2"></i>Profile Information
                        </h3>
                    </div>
                    <form method="POST" action="" class="p-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label class="block text-gray-700 text-sm font-semibold mb-2" for="first_name">
                                    First Name <span class="text-red-500">*</span>
                                </label>
                                <input type="text" id="first_name" name="first_name" required
                                    value="<?php echo htmlspecialchars($user['first_name']); ?>"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                            </div>
                            <div>
                                <label class="block text-gray-700 text-sm font-semibold mb-2" for="last_name">
                                    Last Name <span class="text-red-500">*</span>
                                </label>
                                <input type="text" id="last_name" name="last_name" required
                                    value="<?php echo htmlspecialchars($user['last_name']); ?>"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                            </div>
                        </div>
                        <div class="mb-6">
                            <label class="block text-gray-700 text-sm font-semibold mb-2" for="email">
                                Email Address <span class="text-red-500">*</span>
                            </label>
                            <input type="email" id="email" name="email" required
                                value="<?php echo htmlspecialchars($user['email']); ?>"
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        </div>
                        <div class="flex justify-end">
                            <button type="submit" name="update_profile" class="bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-2 rounded-lg transition">
                                <i class="fas fa-save mr-2"></i>Update Profile
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Change Password -->
                <div class="bg-white rounded-lg shadow">
                    <div class="p-6 border-b border-gray-200">
                        <h3 class="text-xl font-bold text-gray-900">
                            <i class="fas fa-lock text-indigo-600 mr-2"></i>Change Password
                        </h3>
                    </div>
                    <form method="POST" action="" class="p-6">
                        <div class="mb-4">
                            <label class="block text-gray-700 text-sm font-semibold mb-2" for="current_password">
                                Current Password <span class="text-red-500">*</span>
                            </label>
                            <input type="password" id="current_password" name="current_password" required
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        </div>
                        <div class="mb-4">
                            <label class="block text-gray-700 text-sm font-semibold mb-2" for="new_password">
                                New Password <span class="text-red-500">*</span>
                            </label>
                            <input type="password" id="new_password" name="new_password" required minlength="6"
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                            <p class="text-sm text-gray-500 mt-1">Must be at least 6 characters</p>
                        </div>
                        <div class="mb-6">
                            <label class="block text-gray-700 text-sm font-semibold mb-2" for="confirm_password">
                                Confirm New Password <span class="text-red-500">*</span>
                            </label>
                            <input type="password" id="confirm_password" name="confirm_password" required minlength="6"
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        </div>
                        <div class="flex justify-end">
                            <button type="submit" name="change_password" class="bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-2 rounded-lg transition">
                                <i class="fas fa-key mr-2"></i>Change Password
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Account Security -->
                <div class="bg-white rounded-lg shadow">
                    <div class="p-6 border-b border-gray-200">
                        <h3 class="text-xl font-bold text-gray-900">
                            <i class="fas fa-shield-alt text-indigo-600 mr-2"></i>Account Security
                        </h3>
                    </div>
                    <div class="p-6">
                        <div class="space-y-4">
                            <div class="flex items-start">
                                <div class="flex-shrink-0">
                                    <i class="fas fa-check-circle text-green-500 text-xl"></i>
                                </div>
                                <div class="ml-3">
                                    <h4 class="text-sm font-semibold text-gray-900">Password Protected</h4>
                                    <p class="text-sm text-gray-600">Your account is secured with a password</p>
                                </div>
                            </div>
                            <div class="flex items-start">
                                <div class="flex-shrink-0">
                                    <i class="fas fa-check-circle text-green-500 text-xl"></i>
                                </div>
                                <div class="ml-3">
                                    <h4 class="text-sm font-semibold text-gray-900">Email Verified</h4>
                                    <p class="text-sm text-gray-600">Your email address is verified</p>
                                </div>
                            </div>
                            <div class="flex items-start">
                                <div class="flex-shrink-0">
                                    <i class="fas fa-check-circle text-green-500 text-xl"></i>
                                </div>
                                <div class="ml-3">
                                    <h4 class="text-sm font-semibold text-gray-900">Activity Monitoring</h4>
                                    <p class="text-sm text-gray-600">All account activities are logged</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
