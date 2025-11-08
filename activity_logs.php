<?php
require_once '../config/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

Auth::requireRole('admin');

$conn = getDBConnection();

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 50;
$offset = ($page - 1) * $per_page;

// Filters
$user_filter = isset($_GET['user_id']) ? intval($_GET['user_id']) : null;
$action_filter = isset($_GET['action']) ? sanitize($_GET['action']) : null;
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : null;
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : null;

// Build query
$where_clauses = [];
$params = [];
$types = '';

if ($user_filter) {
    $where_clauses[] = "al.user_id = ?";
    $params[] = $user_filter;
    $types .= 'i';
}

if ($action_filter) {
    $where_clauses[] = "al.action = ?";
    $params[] = $action_filter;
    $types .= 's';
}

if ($date_from) {
    $where_clauses[] = "DATE(al.created_at) >= ?";
    $params[] = $date_from;
    $types .= 's';
}

if ($date_to) {
    $where_clauses[] = "DATE(al.created_at) <= ?";
    $params[] = $date_to;
    $types .= 's';
}

$where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

// Get total count
$count_sql = "SELECT COUNT(*) as total FROM activity_logs al $where_sql";
$count_stmt = $conn->prepare($count_sql);
if (!empty($params)) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$total_logs = $count_stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_logs / $per_page);

// Get logs
$logs_sql = "SELECT 
                al.*,
                CONCAT(u.first_name, ' ', u.last_name) as user_name,
                u.role,
                u.email
             FROM activity_logs al
             LEFT JOIN users u ON al.user_id = u.user_id
             $where_sql
             ORDER BY al.created_at DESC
             LIMIT ? OFFSET ?";

$stmt = $conn->prepare($logs_sql);
$params[] = $per_page;
$params[] = $offset;
$types .= 'ii';
$stmt->bind_param($types, ...$params);
$stmt->execute();
$logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get unique actions for filter
$actions = $conn->query("SELECT DISTINCT action FROM activity_logs ORDER BY action")->fetch_all(MYSQLI_ASSOC);

// Get all users for filter
$users = $conn->query("SELECT user_id, CONCAT(first_name, ' ', last_name) as name, role FROM users ORDER BY first_name")->fetch_all(MYSQLI_ASSOC);

$unread_count = getUnreadNotificationCount($_SESSION['user_id']);
closeDBConnection($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Logs - Admin Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-50">
    <?php include 'includes/nav.php'; ?>

    <div class="container mx-auto px-4 py-8">
        <div class="mb-8">
            <h1 class="text-3xl font-bold text-gray-900">Activity Logs</h1>
            <p class="text-gray-600 mt-1">Monitor system activity and user actions</p>
        </div>

        <!-- Filters -->
        <div class="bg-white rounded-lg shadow p-6 mb-6">
            <form method="GET" action="" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">User</label>
                    <select name="user_id" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        <option value="">All Users</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?php echo $user['user_id']; ?>" <?php echo $user_filter == $user['user_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($user['name']); ?> (<?php echo $user['role']; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Action</label>
                    <select name="action" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        <option value="">All Actions</option>
                        <?php foreach ($actions as $action): ?>
                            <option value="<?php echo htmlspecialchars($action['action']); ?>" <?php echo $action_filter == $action['action'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($action['action']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Date From</label>
                    <input type="date" name="date_from" value="<?php echo $date_from; ?>" 
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Date To</label>
                    <input type="date" name="date_to" value="<?php echo $date_to; ?>" 
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                </div>

                <div class="flex items-end space-x-2">
                    <button type="submit" class="flex-1 bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg transition">
                        <i class="fas fa-filter mr-2"></i>Filter
                    </button>
                    <a href="activity_logs.php" class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-4 py-2 rounded-lg transition">
                        <i class="fas fa-redo"></i>
                    </a>
                </div>
            </form>
        </div>

        <!-- Stats -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
            <div class="bg-white rounded-lg shadow p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-600">Total Logs</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($total_logs); ?></p>
                    </div>
                    <i class="fas fa-file-alt text-blue-500 text-2xl"></i>
                </div>
            </div>
            <div class="bg-white rounded-lg shadow p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-600">Current Page</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo $page; ?> / <?php echo max(1, $total_pages); ?></p>
                    </div>
                    <i class="fas fa-list text-green-500 text-2xl"></i>
                </div>
            </div>
            <div class="bg-white rounded-lg shadow p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-600">Showing</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo count($logs); ?></p>
                    </div>
                    <i class="fas fa-eye text-purple-500 text-2xl"></i>
                </div>
            </div>
            <div class="bg-white rounded-lg shadow p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-600">Per Page</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo $per_page; ?></p>
                    </div>
                    <i class="fas fa-layer-group text-orange-500 text-2xl"></i>
                </div>
            </div>
        </div>

        <!-- Logs Table -->
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Timestamp</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Role</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Action</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Details</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">IP Address</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($logs)): ?>
                            <tr>
                                <td colspan="6" class="px-6 py-8 text-center text-gray-500">
                                    <i class="fas fa-inbox text-4xl mb-2"></i>
                                    <p>No activity logs found</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($logs as $log): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?php echo formatDateTime($log['created_at']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900">
                                            <?php echo htmlspecialchars($log['user_name'] ?? 'System'); ?>
                                        </div>
                                        <?php if ($log['email']): ?>
                                            <div class="text-xs text-gray-500"><?php echo htmlspecialchars($log['email']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php if ($log['role']): ?>
                                            <span class="px-2 py-1 text-xs font-semibold rounded-full 
                                                <?php 
                                                    echo $log['role'] == 'admin' ? 'bg-red-100 text-red-800' : 
                                                        ($log['role'] == 'instructor' ? 'bg-blue-100 text-blue-800' : 'bg-green-100 text-green-800'); 
                                                ?>">
                                                <?php echo ucfirst($log['role']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="px-3 py-1 text-xs font-semibold rounded-full bg-indigo-100 text-indigo-800">
                                            <?php echo htmlspecialchars($log['action']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-600 max-w-md truncate">
                                        <?php echo htmlspecialchars($log['details'] ?? '-'); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo htmlspecialchars($log['ip_address'] ?? '-'); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="bg-gray-50 px-6 py-4 flex items-center justify-between border-t border-gray-200">
                    <div class="text-sm text-gray-700">
                        Showing <span class="font-semibold"><?php echo $offset + 1; ?></span> to 
                        <span class="font-semibold"><?php echo min($offset + $per_page, $total_logs); ?></span> of 
                        <span class="font-semibold"><?php echo number_format($total_logs); ?></span> results
                    </div>
                    <div class="flex space-x-2">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?php echo $page - 1; ?><?php echo $user_filter ? '&user_id=' . $user_filter : ''; ?><?php echo $action_filter ? '&action=' . urlencode($action_filter) : ''; ?><?php echo $date_from ? '&date_from=' . $date_from : ''; ?><?php echo $date_to ? '&date_to=' . $date_to : ''; ?>" 
                               class="px-4 py-2 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition">
                                <i class="fas fa-chevron-left"></i> Previous
                            </a>
                        <?php endif; ?>

                        <?php
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $page + 2);
                        for ($i = $start_page; $i <= $end_page; $i++):
                        ?>
                            <a href="?page=<?php echo $i; ?><?php echo $user_filter ? '&user_id=' . $user_filter : ''; ?><?php echo $action_filter ? '&action=' . urlencode($action_filter) : ''; ?><?php echo $date_from ? '&date_from=' . $date_from : ''; ?><?php echo $date_to ? '&date_to=' . $date_to : ''; ?>" 
                               class="px-4 py-2 <?php echo $i == $page ? 'bg-indigo-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-50'; ?> border border-gray-300 rounded-lg transition">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($page < $total_pages): ?>
                            <a href="?page=<?php echo $page + 1; ?><?php echo $user_filter ? '&user_id=' . $user_filter : ''; ?><?php echo $action_filter ? '&action=' . urlencode($action_filter) : ''; ?><?php echo $date_from ? '&date_from=' . $date_from : ''; ?><?php echo $date_to ? '&date_to=' . $date_to : ''; ?>" 
                               class="px-4 py-2 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition">
                                Next <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
