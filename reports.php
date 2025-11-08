<?php
require_once '../config/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

Auth::requireRole('admin');

$conn = getDBConnection();

// Get date range from request or default to last 30 days
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-30 days'));
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

// User Statistics
$user_stats = [
    'total' => $conn->query("SELECT COUNT(*) as count FROM users")->fetch_assoc()['count'],
    'students' => $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'student'")->fetch_assoc()['count'],
    'instructors' => $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'instructor'")->fetch_assoc()['count'],
    'admins' => $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'admin'")->fetch_assoc()['count'],
    'active' => $conn->query("SELECT COUNT(*) as count FROM users WHERE status = 'active'")->fetch_assoc()['count'],
    'inactive' => $conn->query("SELECT COUNT(*) as count FROM users WHERE status = 'inactive'")->fetch_assoc()['count']
];

// Course Statistics
$course_stats = [
    'total_courses' => $conn->query("SELECT COUNT(*) as count FROM courses")->fetch_assoc()['count'],
    'total_classes' => $conn->query("SELECT COUNT(*) as count FROM classes")->fetch_assoc()['count'],
    'active_classes' => $conn->query("SELECT COUNT(*) as count FROM classes WHERE status = 'active'")->fetch_assoc()['count'],
    'completed_classes' => $conn->query("SELECT COUNT(*) as count FROM classes WHERE status = 'completed'")->fetch_assoc()['count']
];

// Enrollment Statistics
$enrollment_stats = [
    'total' => $conn->query("SELECT COUNT(*) as count FROM enrollments")->fetch_assoc()['count'],
    'enrolled' => $conn->query("SELECT COUNT(*) as count FROM enrollments WHERE status = 'enrolled'")->fetch_assoc()['count'],
    'completed' => $conn->query("SELECT COUNT(*) as count FROM enrollments WHERE status = 'completed'")->fetch_assoc()['count'],
    'dropped' => $conn->query("SELECT COUNT(*) as count FROM enrollments WHERE status = 'dropped'")->fetch_assoc()['count']
];

// Attendance Statistics
$attendance_stats = [
    'total_records' => $conn->query("SELECT COUNT(*) as count FROM attendance")->fetch_assoc()['count'],
    'present' => $conn->query("SELECT COUNT(*) as count FROM attendance WHERE status = 'present'")->fetch_assoc()['count'],
    'absent' => $conn->query("SELECT COUNT(*) as count FROM attendance WHERE status = 'absent'")->fetch_assoc()['count'],
    'late' => $conn->query("SELECT COUNT(*) as count FROM attendance WHERE status = 'late'")->fetch_assoc()['count']
];

// Calculate attendance rate
$attendance_stats['rate'] = $attendance_stats['total_records'] > 0 
    ? round((($attendance_stats['present'] + $attendance_stats['late']) / $attendance_stats['total_records']) * 100, 2) 
    : 0;

// Top Performing Students
$top_students_sql = "SELECT 
                        u.first_name, u.last_name, s.student_number, s.program,
                        AVG(g.score / g.max_score * 100) as avg_grade,
                        COUNT(DISTINCT e.class_id) as enrolled_classes
                     FROM users u
                     JOIN students s ON u.user_id = s.user_id
                     JOIN enrollments e ON s.student_id = e.student_id
                     LEFT JOIN grades g ON e.enrollment_id = g.enrollment_id
                     WHERE e.status = 'enrolled' AND g.score IS NOT NULL
                     GROUP BY u.user_id
                     HAVING avg_grade IS NOT NULL
                     ORDER BY avg_grade DESC
                     LIMIT 10";
$top_students = $conn->query($top_students_sql)->fetch_all(MYSQLI_ASSOC);

// At-Risk Students
$at_risk_sql = "SELECT 
                    u.first_name, u.last_name, s.student_number,
                    p.risk_level, p.confidence_score,
                    p.created_at as prediction_date
                FROM predictions p
                JOIN enrollments e ON p.enrollment_id = e.enrollment_id
                JOIN students s ON e.student_id = s.student_id
                JOIN users u ON s.user_id = u.user_id
                WHERE p.prediction_type = 'risk_detection'
                AND p.risk_level IN ('high', 'critical')
                ORDER BY p.confidence_score DESC, p.created_at DESC
                LIMIT 10";
$at_risk_students = $conn->query($at_risk_sql)->fetch_all(MYSQLI_ASSOC);

// Course Enrollment Distribution
$course_distribution_sql = "SELECT 
                                c.course_code, c.course_name,
                                COUNT(DISTINCT cl.class_id) as class_count,
                                COUNT(e.enrollment_id) as enrollment_count
                            FROM courses c
                            LEFT JOIN classes cl ON c.course_id = cl.course_id
                            LEFT JOIN enrollments e ON cl.class_id = e.class_id AND e.status = 'enrolled'
                            GROUP BY c.course_id
                            ORDER BY enrollment_count DESC
                            LIMIT 10";
$course_distribution = $conn->query($course_distribution_sql)->fetch_all(MYSQLI_ASSOC);

// Recent Activity Summary
$activity_summary_sql = "SELECT 
                            action,
                            COUNT(*) as count
                         FROM activity_logs
                         WHERE created_at BETWEEN ? AND ?
                         GROUP BY action
                         ORDER BY count DESC";
$stmt = $conn->prepare($activity_summary_sql);
$stmt->bind_param("ss", $start_date, $end_date);
$stmt->execute();
$activity_summary = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$unread_count = getUnreadNotificationCount($_SESSION['user_id']);
closeDBConnection($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Reports - Admin Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-gray-50">
    <?php include 'includes/nav.php'; ?>

    <div class="container mx-auto px-4 py-8">
        <div class="mb-8 flex justify-between items-center">
            <div>
                <h1 class="text-3xl font-bold text-gray-900">System Reports</h1>
                <p class="text-gray-600 mt-1">Comprehensive analytics and statistics</p>
            </div>
            <div class="flex space-x-3">
                <button onclick="window.print()" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg transition">
                    <i class="fas fa-print mr-2"></i>Print Report
                </button>
                <button onclick="exportReport()" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg transition">
                    <i class="fas fa-file-excel mr-2"></i>Export
                </button>
            </div>
        </div>

        <!-- Date Range Filter -->
        <div class="bg-white rounded-lg shadow p-6 mb-6">
            <form method="GET" action="" class="flex items-end space-x-4">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Start Date</label>
                    <input type="date" name="start_date" value="<?php echo $start_date; ?>" 
                        class="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">End Date</label>
                    <input type="date" name="end_date" value="<?php echo $end_date; ?>" 
                        class="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                </div>
                <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-2 rounded-lg transition">
                    <i class="fas fa-filter mr-2"></i>Apply Filter
                </button>
            </form>
        </div>

        <!-- Overview Statistics -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-gray-700">Total Users</h3>
                    <i class="fas fa-users text-blue-500 text-2xl"></i>
                </div>
                <p class="text-3xl font-bold text-gray-900"><?php echo $user_stats['total']; ?></p>
                <div class="mt-4 text-sm text-gray-600">
                    <div class="flex justify-between"><span>Students:</span><span class="font-semibold"><?php echo $user_stats['students']; ?></span></div>
                    <div class="flex justify-between"><span>Instructors:</span><span class="font-semibold"><?php echo $user_stats['instructors']; ?></span></div>
                    <div class="flex justify-between"><span>Admins:</span><span class="font-semibold"><?php echo $user_stats['admins']; ?></span></div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-gray-700">Courses & Classes</h3>
                    <i class="fas fa-book text-green-500 text-2xl"></i>
                </div>
                <p class="text-3xl font-bold text-gray-900"><?php echo $course_stats['total_courses']; ?></p>
                <div class="mt-4 text-sm text-gray-600">
                    <div class="flex justify-between"><span>Total Classes:</span><span class="font-semibold"><?php echo $course_stats['total_classes']; ?></span></div>
                    <div class="flex justify-between"><span>Active:</span><span class="font-semibold"><?php echo $course_stats['active_classes']; ?></span></div>
                    <div class="flex justify-between"><span>Completed:</span><span class="font-semibold"><?php echo $course_stats['completed_classes']; ?></span></div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-gray-700">Enrollments</h3>
                    <i class="fas fa-user-graduate text-purple-500 text-2xl"></i>
                </div>
                <p class="text-3xl font-bold text-gray-900"><?php echo $enrollment_stats['total']; ?></p>
                <div class="mt-4 text-sm text-gray-600">
                    <div class="flex justify-between"><span>Enrolled:</span><span class="font-semibold"><?php echo $enrollment_stats['enrolled']; ?></span></div>
                    <div class="flex justify-between"><span>Completed:</span><span class="font-semibold"><?php echo $enrollment_stats['completed']; ?></span></div>
                    <div class="flex justify-between"><span>Dropped:</span><span class="font-semibold"><?php echo $enrollment_stats['dropped']; ?></span></div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-gray-700">Attendance Rate</h3>
                    <i class="fas fa-calendar-check text-orange-500 text-2xl"></i>
                </div>
                <p class="text-3xl font-bold text-gray-900"><?php echo $attendance_stats['rate']; ?>%</p>
                <div class="mt-4 text-sm text-gray-600">
                    <div class="flex justify-between"><span>Present:</span><span class="font-semibold"><?php echo $attendance_stats['present']; ?></span></div>
                    <div class="flex justify-between"><span>Absent:</span><span class="font-semibold"><?php echo $attendance_stats['absent']; ?></span></div>
                    <div class="flex justify-between"><span>Late:</span><span class="font-semibold"><?php echo $attendance_stats['late']; ?></span></div>
                </div>
            </div>
        </div>

        <!-- Charts Row -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="text-xl font-bold text-gray-900 mb-4">User Distribution</h3>
                <canvas id="userChart"></canvas>
            </div>
            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="text-xl font-bold text-gray-900 mb-4">Enrollment Status</h3>
                <canvas id="enrollmentChart"></canvas>
            </div>
        </div>

        <!-- Top Performing Students -->
        <div class="bg-white rounded-lg shadow mb-8">
            <div class="p-6 border-b border-gray-200">
                <h3 class="text-xl font-bold text-gray-900">
                    <i class="fas fa-trophy text-yellow-500 mr-2"></i>Top Performing Students
                </h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Rank</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Student Number</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Program</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Classes</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Average Grade</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($top_students)): ?>
                            <tr>
                                <td colspan="6" class="px-6 py-8 text-center text-gray-500">No data available</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($top_students as $index => $student): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="text-lg font-bold text-gray-900">#<?php echo $index + 1; ?></span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?php echo htmlspecialchars($student['student_number']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                        <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                        <?php echo htmlspecialchars($student['program']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?php echo $student['enrolled_classes']; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="bg-green-100 text-green-800 px-3 py-1 rounded-full text-sm font-semibold">
                                            <?php echo number_format($student['avg_grade'], 2); ?>%
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- At-Risk Students -->
        <div class="bg-white rounded-lg shadow mb-8">
            <div class="p-6 border-b border-gray-200">
                <h3 class="text-xl font-bold text-gray-900">
                    <i class="fas fa-exclamation-triangle text-red-500 mr-2"></i>At-Risk Students
                </h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Student Number</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Risk Level</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Confidence</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Prediction Date</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($at_risk_students)): ?>
                            <tr>
                                <td colspan="5" class="px-6 py-8 text-center text-gray-500">No at-risk students identified</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($at_risk_students as $student): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?php echo htmlspecialchars($student['student_number']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                        <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="px-3 py-1 rounded-full text-xs font-semibold <?php echo getRiskBadgeClass($student['risk_level']); ?>">
                                            <?php echo strtoupper($student['risk_level']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?php echo number_format($student['confidence_score'] * 100, 1); ?>%
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo formatDateTime($student['prediction_date']); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Course Enrollment Distribution -->
        <div class="bg-white rounded-lg shadow">
            <div class="p-6 border-b border-gray-200">
                <h3 class="text-xl font-bold text-gray-900">
                    <i class="fas fa-chart-bar text-indigo-500 mr-2"></i>Course Enrollment Distribution
                </h3>
            </div>
            <div class="p-6">
                <canvas id="courseChart"></canvas>
            </div>
        </div>
    </div>

    <script>
        // User Distribution Chart
        const userCtx = document.getElementById('userChart').getContext('2d');
        new Chart(userCtx, {
            type: 'doughnut',
            data: {
                labels: ['Students', 'Instructors', 'Admins', 'Inactive'],
                datasets: [{
                    data: [
                        <?php echo $user_stats['students']; ?>,
                        <?php echo $user_stats['instructors']; ?>,
                        <?php echo $user_stats['admins']; ?>,
                        <?php echo $user_stats['inactive']; ?>
                    ],
                    backgroundColor: ['#3B82F6', '#10B981', '#F59E0B', '#EF4444']
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

        // Enrollment Status Chart
        const enrollmentCtx = document.getElementById('enrollmentChart').getContext('2d');
        new Chart(enrollmentCtx, {
            type: 'pie',
            data: {
                labels: ['Enrolled', 'Completed', 'Dropped'],
                datasets: [{
                    data: [
                        <?php echo $enrollment_stats['enrolled']; ?>,
                        <?php echo $enrollment_stats['completed']; ?>,
                        <?php echo $enrollment_stats['dropped']; ?>
                    ],
                    backgroundColor: ['#8B5CF6', '#10B981', '#EF4444']
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

        // Course Distribution Chart
        const courseCtx = document.getElementById('courseChart').getContext('2d');
        new Chart(courseCtx, {
            type: 'bar',
            data: {
                labels: [<?php echo implode(',', array_map(function($c) { return '"' . addslashes($c['course_code']) . '"'; }, $course_distribution)); ?>],
                datasets: [{
                    label: 'Enrollments',
                    data: [<?php echo implode(',', array_column($course_distribution, 'enrollment_count')); ?>],
                    backgroundColor: '#6366F1'
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });

        function exportReport() {
            alert('Export functionality would generate a CSV/Excel file with all report data');
        }
    </script>
</body>
</html>
