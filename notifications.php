<?php
require_once '../config/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

Auth::requireRole('admin');

$conn = getDBConnection();

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['mark_read'])) {
        $notification_id = intval($_POST['notification_id']);
        $sql = "UPDATE notifications SET is_read = TRUE WHERE notification_id = ? AND user_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $notification_id, $_SESSION['user_id']);
        $stmt->execute();
    } elseif (isset($_POST['mark_all_read'])) {
        $sql = "UPDATE notifications SET is_read = TRUE WHERE user_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
    } elseif (isset($_POST['delete_notification'])) {
        $notification_id = intval($_POST['notification_id']);
        $sql = "DELETE FROM notifications WHERE notification_id = ? AND user_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $notification_id, $_SESSION['user_id']);
        $stmt->execute();
    }
    header("Location: notifications.php");
    exit();
}

// Get filter
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';

// Build query based on filter
$where_clause = "WHERE user_id = ?";
if ($filter === 'unread') {
    $where_clause .= " AND is_read = FALSE";
} elseif ($filter === 'read') {
    $where_clause .= " AND is_read = TRUE";
}

// Get notifications
$sql = "SELECT * FROM notifications $where_clause ORDER BY created_at DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$notifications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get counts
$unread_count = getUnreadNotificationCount($_SESSION['user_id']);
$total_count = $conn->query("SELECT COUNT(*) as count FROM notifications WHERE user_id = {$_SESSION['user_id']}")->fetch_assoc()['count'];
$read_count = $total_count - $unread_count;

closeDBConnection($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - Admin Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-50">
    <?php include 'includes/nav.php'; ?>

    <div class="container mx-auto px-4 py-8">
        <div class="mb-8 flex justify-between items-center">
            <div>
                <h1 class="text-3xl font-bold text-gray-900">Notifications</h1>
                <p class="text-gray-600 mt-1">Stay updated with system activities</p>
            </div>
            <?php if ($unread_count > 0): ?>
                <form method="POST" action="">
                    <button type="submit" name="mark_all_read" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg transition">
                        <i class="fas fa-check-double mr-2"></i>Mark All as Read
                    </button>
                </form>
            <?php endif; ?>
        </div>

        <!-- Stats -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <div class="bg-white rounded-lg shadow p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-600">Total</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo $total_count; ?></p>
                    </div>
                    <i class="fas fa-bell text-blue-500 text-2xl"></i>
                </div>
            </div>
            <div class="bg-white rounded-lg shadow p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-600">Unread</p>
                        <p class="text-2xl font-bold text-red-600"><?php echo $unread_count; ?></p>
                    </div>
                    <i class="fas fa-envelope text-red-500 text-2xl"></i>
                </div>
            </div>
            <div class="bg-white rounded-lg shadow p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-600">Read</p>
                        <p class="text-2xl font-bold text-green-600"><?php echo $read_count; ?></p>
                    </div>
                    <i class="fas fa-envelope-open text-green-500 text-2xl"></i>
                </div>
            </div>
        </div>

        <!-- Filter Tabs -->
        <div class="bg-white rounded-lg shadow mb-6">
            <div class="flex border-b border-gray-200">
                <a href="?filter=all" class="px-6 py-4 text-sm font-medium <?php echo $filter === 'all' ? 'text-indigo-600 border-b-2 border-indigo-600' : 'text-gray-600 hover:text-gray-900'; ?>">
                    All (<?php echo $total_count; ?>)
                </a>
                <a href="?filter=unread" class="px-6 py-4 text-sm font-medium <?php echo $filter === 'unread' ? 'text-indigo-600 border-b-2 border-indigo-600' : 'text-gray-600 hover:text-gray-900'; ?>">
                    Unread (<?php echo $unread_count; ?>)
                </a>
                <a href="?filter=read" class="px-6 py-4 text-sm font-medium <?php echo $filter === 'read' ? 'text-indigo-600 border-b-2 border-indigo-600' : 'text-gray-600 hover:text-gray-900'; ?>">
                    Read (<?php echo $read_count; ?>)
                </a>
            </div>
        </div>

        <!-- Notifications List -->
        <div class="space-y-4">
            <?php if (empty($notifications)): ?>
                <div class="bg-white rounded-lg shadow p-12 text-center">
                    <i class="fas fa-inbox text-gray-300 text-6xl mb-4"></i>
                    <h3 class="text-xl font-semibold text-gray-700 mb-2">No Notifications</h3>
                    <p class="text-gray-500">You're all caught up!</p>
                </div>
            <?php else: ?>
                <?php foreach ($notifications as $notification): ?>
                    <div class="bg-white rounded-lg shadow hover:shadow-md transition <?php echo !$notification['is_read'] ? 'border-l-4 border-indigo-600' : ''; ?>">
                        <div class="p-6">
                            <div class="flex items-start justify-between">
                                <div class="flex-1">
                                    <div class="flex items-center space-x-3 mb-2">
                                        <span class="flex-shrink-0 w-10 h-10 rounded-full flex items-center justify-center
                                            <?php 
                                                $type_colors = [
                                                    'success' => 'bg-green-100 text-green-600',
                                                    'warning' => 'bg-yellow-100 text-yellow-600',
                                                    'error' => 'bg-red-100 text-red-600',
                                                    'info' => 'bg-blue-100 text-blue-600'
                                                ];
                                                echo $type_colors[$notification['type']] ?? 'bg-gray-100 text-gray-600';
                                            ?>">
                                            <i class="fas 
                                                <?php 
                                                    $type_icons = [
                                                        'success' => 'fa-check-circle',
                                                        'warning' => 'fa-exclamation-triangle',
                                                        'error' => 'fa-times-circle',
                                                        'info' => 'fa-info-circle'
                                                    ];
                                                    echo $type_icons[$notification['type']] ?? 'fa-bell';
                                                ?>">
                                            </i>
                                        </span>
                                        <div class="flex-1">
                                            <h3 class="text-lg font-semibold text-gray-900">
                                                <?php echo htmlspecialchars($notification['title']); ?>
                                                <?php if (!$notification['is_read']): ?>
                                                    <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-indigo-100 text-indigo-800">
                                                        New
                                                    </span>
                                                <?php endif; ?>
                                            </h3>
                                            <p class="text-sm text-gray-500">
                                                <i class="far fa-clock mr-1"></i>
                                                <?php echo formatDateTime($notification['created_at']); ?>
                                            </p>
                                        </div>
                                    </div>
                                    <p class="text-gray-700 ml-13">
                                        <?php echo htmlspecialchars($notification['message']); ?>
                                    </p>
                                </div>
                                <div class="flex space-x-2 ml-4">
                                    <?php if (!$notification['is_read']): ?>
                                        <form method="POST" action="" class="inline">
                                            <input type="hidden" name="notification_id" value="<?php echo $notification['notification_id']; ?>">
                                            <button type="submit" name="mark_read" class="text-indigo-600 hover:text-indigo-800 p-2" title="Mark as read">
                                                <i class="fas fa-check"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="POST" action="" class="inline" onsubmit="return confirm('Delete this notification?')">
                                        <input type="hidden" name="notification_id" value="<?php echo $notification['notification_id']; ?>">
                                        <button type="submit" name="delete_notification" class="text-red-600 hover:text-red-800 p-2" title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
