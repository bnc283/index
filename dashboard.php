<?php
require_once '../config/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

Auth::requireRole('admin');

$conn = getDBConnection();

// Get statistics
$stats = [];

// Total users by role
$users_sql = "SELECT role, COUNT(*) as count FROM users WHERE status = 'active' GROUP BY role";
$result = $conn->query($users_sql);
while ($row = $result->fetch_assoc()) {
    $stats[$row['role']] = $row['count'];
}

// Total courses and classes
$courses_count = $conn->query("SELECT COUNT(*) as count FROM courses")->fetch_assoc()['count'];
$active_classes = $conn->query("SELECT COUNT(*) as count FROM classes WHERE status = 'active'")->fetch_assoc()['count'];

// Total enrollments
$enrollments_count = $conn->query("SELECT COUNT(*) as count FROM enrollments WHERE status = 'enrolled'")->fetch_assoc()['count'];

// Recent activity logs
$logs_sql = "SELECT 
                al.*,
                CONCAT(u.first_name, ' ', u.last_name) as user_name,
                u.role
             FROM activity_logs al
             LEFT JOIN users u ON al.user_id = u.user_id
             ORDER BY al.created_at DESC
             LIMIT 10";
$recent_logs = $conn->query($logs_sql)->fetch_all(MYSQLI_ASSOC);

// At-risk students summary
$at_risk_sql = "SELECT 
                    risk_level,
                    COUNT(*) as count
                FROM predictions
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                AND prediction_type = 'risk_detection'
                GROUP BY risk_level";
$at_risk_result = $conn->query($at_risk_sql);
$at_risk_summary = [];
while ($row = $at_risk_result->fetch_assoc()) {
    $at_risk_summary[$row['risk_level']] = $row['count'];
}

$unread_count = getUnreadNotificationCount($_SESSION['user_id']);

closeDBConnection($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Student Analytics System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-gray-50">
    <!-- Navigation -->
    <?php include 'includes/nav.php'; ?>

    <div class="container mx-auto px-4 py-8">
        <!-- Welcome Header -->
        <div class="mb-8">
            <h1 class="text-3xl font-bold text-gray-900">System Administration</h1>
            <p class="text-gray-600 mt-1">Manage users, courses, and system settings</p>
        </div>

        <!-- Stats Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-sm font-medium">Total Students</p>
                        <p class="text-3xl font-bold text-gray-900 mt-1"><?php echo $stats['student'] ?? 0; ?></p>
                    </div>
                    <div class="bg-blue-100 rounded-full p-3">
                        <i class="fas fa-user-graduate text-blue-600 text-xl"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-sm font-medium">Total Instructors</p>
                        <p class="text-3xl font-bold text-gray-900 mt-1"><?php echo $stats['instructor'] ?? 0; ?></p>
                    </div>
                    <div class="bg-green-100 rounded-full p-3">
                        <i class="fas fa-chalkboard-teacher text-green-600 text-xl"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-sm font-medium">Active Classes</p>
                        <p class="text-3xl font-bold text-gray-900 mt-1"><?php echo $active_classes; ?></p>
                    </div>
                    <div class="bg-purple-100 rounded-full p-3">
                        <i class="fas fa-chalkboard text-purple-600 text-xl"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-sm font-medium">Total Enrollments</p>
                        <p class="text-3xl font-bold text-gray-900 mt-1"><?php echo $enrollments_count; ?></p>
                    </div>
                    <div class="bg-yellow-100 rounded-full p-3">
                        <i class="fas fa-users text-yellow-600 text-xl"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Risk Summary -->
        <?php if (!empty($at_risk_summary)): ?>
        <div class="bg-white rounded-lg shadow mb-8 p-6">
            <h2 class="text-xl font-bold text-gray-900 mb-4">
                <i class="fas fa-exclamation-triangle mr-2 text-red-600"></i>At-Risk Students (Last 7 Days)
            </h2>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="text-center p-4 bg-green-50 rounded-lg">
                    <p class="text-2xl font-bold text-green-600"><?php echo $at_risk_summary['low'] ?? 0; ?></p>
                    <p class="text-sm text-gray-600">Low Risk</p>
                </div>
                <div class="text-center p-4 bg-yellow-50 rounded-lg">
                    <p class="text-2xl font-bold text-yellow-600"><?php echo $at_risk_summary['medium'] ?? 0; ?></p>
                    <p class="text-sm text-gray-600">Medium Risk</p>
                </div>
                <div class="text-center p-4 bg-orange-50 rounded-lg">
                    <p class="text-2xl font-bold text-orange-600"><?php echo $at_risk_summary['high'] ?? 0; ?></p>
                    <p class="text-sm text-gray-600">High Risk</p>
                </div>
                <div class="text-center p-4 bg-red-50 rounded-lg">
                    <p class="text-2xl font-bold text-red-600"><?php echo $at_risk_summary['critical'] ?? 0; ?></p>
                    <p class="text-sm text-gray-600">Critical Risk</p>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <!-- Main Content -->
            <div class="lg:col-span-2 space-y-6">
                <!-- Recent Activity -->
                <div class="bg-white rounded-lg shadow">
                    <div class="p-6 border-b border-gray-200">
                        <h2 class="text-xl font-bold text-gray-900">
                            <i class="fas fa-history mr-2 text-indigo-600"></i>Recent Activity
                        </h2>
                    </div>
                    <div class="p-6">
                        <?php if (empty($recent_logs)): ?>
                            <p class="text-gray-500 text-center py-8">No recent activity</p>
                        <?php else: ?>
                            <div class="space-y-3">
                                <?php foreach ($recent_logs as $log): ?>
                                    <div class="flex items-start p-3 bg-gray-50 rounded-lg">
                                        <div class="flex-shrink-0 mt-1">
                                            <?php
                                            $icon_class = 'fa-circle';
                                            $icon_color = 'text-gray-400';
                                            if (strpos($log['action'], 'login') !== false) {
                                                $icon_class = 'fa-sign-in-alt';
                                                $icon_color = 'text-green-500';
                                            } elseif (strpos($log['action'], 'create') !== false) {
                                                $icon_class = 'fa-plus';
                                                $icon_color = 'text-blue-500';
                                            } elseif (strpos($log['action'], 'update') !== false) {
                                                $icon_class = 'fa-edit';
                                                $icon_color = 'text-yellow-500';
                                            } elseif (strpos($log['action'], 'delete') !== false) {
                                                $icon_class = 'fa-trash';
                                                $icon_color = 'text-red-500';
                                            }
                                            ?>
                                            <i class="fas <?php echo $icon_class; ?> <?php echo $icon_color; ?>"></i>
                                        </div>
                                        <div class="ml-3 flex-1">
                                            <p class="text-sm text-gray-900">
                                                <span class="font-medium"><?php echo htmlspecialchars($log['user_name'] ?? 'System'); ?></span>
                                                <span class="text-gray-600"> - <?php echo htmlspecialchars($log['action']); ?></span>
                                            </p>
                                            <?php if ($log['details']): ?>
                                                <p class="text-xs text-gray-500 mt-1"><?php echo htmlspecialchars($log['details']); ?></p>
                                            <?php endif; ?>
                                            <p class="text-xs text-gray-400 mt-1"><?php echo formatDateTime($log['created_at']); ?></p>
                                        </div>
                                        <?php if ($log['role']): ?>
                                            <span class="px-2 py-1 text-xs rounded-full <?php echo getStatusBadgeClass($log['role']); ?>">
                                                <?php echo ucfirst($log['role']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- System Overview Chart -->
                <div class="bg-white rounded-lg shadow">
                    <div class="p-6 border-b border-gray-200">
                        <h2 class="text-xl font-bold text-gray-900">
                            <i class="fas fa-chart-pie mr-2 text-indigo-600"></i>User Distribution
                        </h2>
                    </div>
                    <div class="p-6">
                        <canvas id="userChart" height="100"></canvas>
                    </div>
                </div>
            </div>

            <!-- Sidebar -->
            <div class="space-y-6">
                <!-- Quick Actions -->
                <div class="bg-white rounded-lg shadow">
                    <div class="p-6 border-b border-gray-200">
                        <h2 class="text-xl font-bold text-gray-900">
                            <i class="fas fa-bolt mr-2 text-indigo-600"></i>Quick Actions
                        </h2>
                    </div>
                    <div class="p-6 space-y-2">
                        <a href="manage_users.php" class="block w-full text-left px-4 py-3 bg-indigo-50 hover:bg-indigo-100 rounded-lg transition">
                            <i class="fas fa-users-cog mr-2 text-indigo-600"></i>
                            <span class="font-medium text-gray-900">Manage Users</span>
                        </a>
                        <a href="manage_courses.php" class="block w-full text-left px-4 py-3 bg-indigo-50 hover:bg-indigo-100 rounded-lg transition">
                            <i class="fas fa-book mr-2 text-indigo-600"></i>
                            <span class="font-medium text-gray-900">Manage Courses</span>
                        </a>
                        <a href="grading_systems.php" class="block w-full text-left px-4 py-3 bg-indigo-50 hover:bg-indigo-100 rounded-lg transition">
                            <i class="fas fa-cog mr-2 text-indigo-600"></i>
                            <span class="font-medium text-gray-900">Grading Systems</span>
                        </a>
                        <a href="reports.php" class="block w-full text-left px-4 py-3 bg-indigo-50 hover:bg-indigo-100 rounded-lg transition">
                            <i class="fas fa-chart-bar mr-2 text-indigo-600"></i>
                            <span class="font-medium text-gray-900">System Reports</span>
                        </a>
                        <a href="activity_logs.php" class="block w-full text-left px-4 py-3 bg-indigo-50 hover:bg-indigo-100 rounded-lg transition">
                            <i class="fas fa-file-alt mr-2 text-indigo-600"></i>
                            <span class="font-medium text-gray-900">Activity Logs</span>
                        </a>
                    </div>
                </div>

                <!-- System Info -->
                <div class="bg-white rounded-lg shadow">
                    <div class="p-6 border-b border-gray-200">
                        <h2 class="text-xl font-bold text-gray-900">
                            <i class="fas fa-info-circle mr-2 text-indigo-600"></i>System Info
                        </h2>
                    </div>
                    <div class="p-6 space-y-3 text-sm">
                        <div class="flex justify-between">
                            <span class="text-gray-600">Total Courses:</span>
                            <span class="font-semibold text-gray-900"><?php echo $courses_count; ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-600">Active Classes:</span>
                            <span class="font-semibold text-gray-900"><?php echo $active_classes; ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-600">Total Enrollments:</span>
                            <span class="font-semibold text-gray-900"><?php echo $enrollments_count; ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-600">System Version:</span>
                            <span class="font-semibold text-gray-900">1.0.0</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // User Distribution Chart
        const ctx = document.getElementById('userChart').getContext('2d');
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['Students', 'Instructors', 'Admins'],
                datasets: [{
                    data: [
                        <?php echo $stats['student'] ?? 0; ?>,
                        <?php echo $stats['instructor'] ?? 0; ?>,
                        <?php echo $stats['admin'] ?? 0; ?>
                    ],
                    backgroundColor: [
                        'rgb(59, 130, 246)',
                        'rgb(34, 197, 94)',
                        'rgb(168, 85, 247)'
                    ]
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
    </script>
</body>
</html>
