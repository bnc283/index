<?php
require_once '../config/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

Auth::requireRole('admin');

$conn = getDBConnection();

// Handle course actions
$message = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_course'])) {
        $course_code = sanitize($_POST['course_code']);
        $course_name = sanitize($_POST['course_name']);
        $description = sanitize($_POST['description']);
        $lecture_units = isset($_POST['lecture_units']) ? intval($_POST['lecture_units']) : 0;
        $laboratory_units = isset($_POST['laboratory_units']) && $_POST['laboratory_units'] !== '' ? intval($_POST['laboratory_units']) : 0;
        $units = max(0, $lecture_units) + max(0, $laboratory_units);
        
        $sql = "INSERT INTO courses (course_code, course_name, description, units) VALUES (?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssi", $course_code, $course_name, $description, $units);
        
        if ($stmt->execute()) {
            // Immediately proceed to class creation for this course
            $new_course_id = $stmt->insert_id;
            $class_type = $laboratory_units > 0 ? 'with_lab' : 'non_lab';
            closeDBConnection($conn);
            header('Location: create_class.php?course_id=' . $new_course_id . '&class_type=' . $class_type);
            exit();
        } else {
            $message = ['type' => 'error', 'text' => 'Error creating course: ' . $conn->error];
        }
    } elseif (isset($_POST['update_course'])) {
        $course_id = intval($_POST['course_id']);
        $course_code = sanitize($_POST['course_code']);
        $course_name = sanitize($_POST['course_name']);
        $description = sanitize($_POST['description']);
        $units = intval($_POST['units']);
        
        $sql = "UPDATE courses SET course_code = ?, course_name = ?, description = ?, units = ? WHERE course_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssii", $course_code, $course_name, $description, $units, $course_id);
        
        if ($stmt->execute()) {
            $message = ['type' => 'success', 'text' => 'Course updated successfully'];
        } else {
            $message = ['type' => 'error', 'text' => 'Error updating course: ' . $conn->error];
        }
    } elseif (isset($_POST['delete_course'])) {
        $course_id = intval($_POST['course_id']);
        
        // Check if course has classes
        $check_sql = "SELECT COUNT(*) as count FROM classes WHERE course_id = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("i", $course_id);
        $check_stmt->execute();
        $result = $check_stmt->get_result()->fetch_assoc();
        
        if ($result['count'] > 0) {
            $message = ['type' => 'error', 'text' => 'Cannot delete course with existing classes'];
        } else {
            $sql = "DELETE FROM courses WHERE course_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $course_id);
            
            if ($stmt->execute()) {
                $message = ['type' => 'success', 'text' => 'Course deleted successfully'];
            } else {
                $message = ['type' => 'error', 'text' => 'Error deleting course: ' . $conn->error];
            }
        }
    }
}

// Get all courses with class count
$courses_sql = "SELECT c.*, 
                COUNT(DISTINCT cl.class_id) as class_count,
                COUNT(DISTINCT e.enrollment_id) as enrollment_count
                FROM courses c
                LEFT JOIN classes cl ON c.course_id = cl.course_id
                LEFT JOIN enrollments e ON cl.class_id = e.class_id
                GROUP BY c.course_id
                ORDER BY c.course_code";
$courses = $conn->query($courses_sql)->fetch_all(MYSQLI_ASSOC);

$unread_count = getUnreadNotificationCount($_SESSION['user_id']);
closeDBConnection($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Courses - Admin Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-50">
    <?php include 'includes/nav.php'; ?>

    <div class="container mx-auto px-4 py-8">
        <div class="mb-8 flex justify-between items-center">
            <div>
                <h1 class="text-3xl font-bold text-gray-900">Manage Courses</h1>
                <p class="text-gray-600 mt-1">Create, edit, and manage course catalog</p>
            </div>
            <button onclick="openCreateModal()" class="bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-3 rounded-lg font-semibold transition">
                <i class="fas fa-plus mr-2"></i>Add New Course
            </button>
        </div>

        <?php if ($message): ?>
            <div class="mb-6 p-4 rounded-lg <?php echo $message['type'] === 'success' ? 'bg-green-50 border border-green-200 text-green-700' : 'bg-red-50 border border-red-200 text-red-700'; ?>">
                <i class="fas <?php echo $message['type'] === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?> mr-2"></i>
                <?php echo $message['text']; ?>
            </div>
            <script>try{ showToast(<?php echo json_encode($message['text']); ?>, <?php echo json_encode($message['type']); ?>);}catch(e){}</script>
        <?php endif; ?>

        <!-- Courses Table -->
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Course Code</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Course Name</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Units</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Classes</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Enrollments</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($courses)): ?>
                        <tr>
                            <td colspan="7" class="px-6 py-8 text-center text-gray-500">
                                <i class="fas fa-book text-4xl mb-2"></i>
                                <p>No courses found. Create your first course to get started.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($courses as $course): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="font-semibold text-gray-900"><?php echo htmlspecialchars($course['course_code']); ?></span>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($course['course_name']); ?></div>
                                    <?php if ($course['description']): ?>
                                        <div class="text-sm text-gray-500 truncate max-w-md"><?php echo htmlspecialchars($course['description']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo $course['units']; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <span class="bg-blue-100 text-blue-800 px-2 py-1 rounded-full text-xs font-medium">
                                        <?php echo $course['class_count']; ?> classes
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <span class="bg-green-100 text-green-800 px-2 py-1 rounded-full text-xs font-medium">
                                        <?php echo $course['enrollment_count']; ?> students
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo formatDate($course['created_at']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                    <a href="create_class.php?course_id=<?php echo $course['course_id']; ?>" class="text-green-600 hover:text-green-900 mr-3">
                                        <i class="fas fa-chalkboard"></i> Create Class
                                    </a>
                                    <button onclick='openEditModal(<?php echo json_encode($course); ?>)' class="text-indigo-600 hover:text-indigo-900 mr-3">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <button onclick="confirmDelete(<?php echo $course['course_id']; ?>, '<?php echo htmlspecialchars($course['course_code']); ?>', <?php echo $course['class_count']; ?>)" class="text-red-600 hover:text-red-900">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Create/Edit Course Modal -->
    <div id="courseModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-5 border w-full max-w-2xl shadow-lg rounded-lg bg-white">
            <div class="flex justify-between items-center mb-4">
                <h3 id="modalTitle" class="text-2xl font-bold text-gray-900">Add New Course</h3>
                <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-2xl"></i>
                </button>
            </div>
            <form id="courseForm" method="POST" action="">
                <input type="hidden" id="course_id" name="course_id">
                
                <div class="grid grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-gray-700 text-sm font-semibold mb-2" for="course_code">
                            Course Code <span class="text-red-500">*</span>
                        </label>
                        <input type="text" id="course_code" name="course_code" required
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500"
                            placeholder="e.g., CS101">
                    </div>
                    <!-- Single Units (Edit mode) -->
                    <div id="units_block" class="hidden">
                        <label class="block text-gray-700 text-sm font-semibold mb-2" for="units">
                            Units <span class="text-red-500">*</span>
                        </label>
                        <input type="number" id="units" name="units" min="1" max="12" value="3"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    </div>
                    <!-- Lecture/Lab Units (Create mode) -->
                    <div id="lec_units_block">
                        <label class="block text-gray-700 text-sm font-semibold mb-2" for="lecture_units">
                            Lecture Units <span class="text-red-500">*</span>
                        </label>
                        <input type="number" id="lecture_units" name="lecture_units" required min="0" max="12" value="3"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    </div>
                    <div id="lab_units_block">
                        <label class="block text-gray-700 text-sm font-semibold mb-2" for="laboratory_units">
                            Laboratory Units (optional)
                        </label>
                        <input type="number" id="laboratory_units" name="laboratory_units" min="0" max="12" value="0"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    </div>
                </div>

                <div class="mb-4">
                    <label class="block text-gray-700 text-sm font-semibold mb-2" for="course_name">
                        Course Name <span class="text-red-500">*</span>
                    </label>
                    <input type="text" id="course_name" name="course_name" required
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500"
                        placeholder="e.g., Introduction to Computer Science">
                </div>

                <div class="mb-6">
                    <label class="block text-gray-700 text-sm font-semibold mb-2" for="description">
                        Description
                    </label>
                    <textarea id="description" name="description" rows="4"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500"
                        placeholder="Brief description of the course..."></textarea>
                </div>

                <div class="flex justify-end space-x-3">
                    <button type="button" onclick="closeModal()" class="px-6 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition">
                        Cancel
                    </button>
                    <button type="submit" id="submitBtn" name="create_course" class="px-6 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition">
                        <i class="fas fa-save mr-2"></i>Create Course
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
                <h3 class="text-xl font-bold text-gray-900 mb-2">Delete Course</h3>
                <p class="text-gray-600 mb-6" id="deleteMessage"></p>
                <form method="POST" action="">
                    <input type="hidden" id="delete_course_id" name="course_id">
                    <div class="flex justify-center space-x-3">
                        <button type="button" onclick="closeDeleteModal()" class="px-6 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition">
                            Cancel
                        </button>
                        <button type="submit" name="delete_course" class="px-6 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition">
                            <i class="fas fa-trash mr-2"></i>Delete
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function openCreateModal() {
            document.getElementById('modalTitle').textContent = 'Add New Course';
            document.getElementById('courseForm').reset();
            document.getElementById('course_id').value = '';
            document.getElementById('submitBtn').name = 'create_course';
            document.getElementById('submitBtn').innerHTML = '<i class="fas fa-save mr-2"></i>Create Course';
            document.getElementById('courseModal').classList.remove('hidden');
            // Toggle blocks for create
            document.getElementById('units_block').classList.add('hidden');
            document.getElementById('lec_units_block').classList.remove('hidden');
            document.getElementById('lab_units_block').classList.remove('hidden');
        }

        function openEditModal(course) {
            document.getElementById('modalTitle').textContent = 'Edit Course';
            document.getElementById('course_id').value = course.course_id;
            document.getElementById('course_code').value = course.course_code;
            document.getElementById('course_name').value = course.course_name;
            document.getElementById('description').value = course.description || '';
            document.getElementById('units').value = course.units;
            document.getElementById('submitBtn').name = 'update_course';
            document.getElementById('submitBtn').innerHTML = '<i class="fas fa-save mr-2"></i>Update Course';
            document.getElementById('courseModal').classList.remove('hidden');
            // Toggle blocks for edit
            document.getElementById('units_block').classList.remove('hidden');
            document.getElementById('lec_units_block').classList.add('hidden');
            document.getElementById('lab_units_block').classList.add('hidden');
        }

        function closeModal() {
            document.getElementById('courseModal').classList.add('hidden');
        }

        function confirmDelete(courseId, courseCode, classCount) {
            if (classCount > 0) {
                document.getElementById('deleteMessage').textContent = 
                    `Cannot delete "${courseCode}" because it has ${classCount} associated class(es). Please remove the classes first.`;
                document.querySelector('#deleteModal button[name="delete_course"]').disabled = true;
                document.querySelector('#deleteModal button[name="delete_course"]').classList.add('opacity-50', 'cursor-not-allowed');
            } else {
                document.getElementById('deleteMessage').textContent = 
                    `Are you sure you want to delete course "${courseCode}"? This action cannot be undone.`;
                document.querySelector('#deleteModal button[name="delete_course"]').disabled = false;
                document.querySelector('#deleteModal button[name="delete_course"]').classList.remove('opacity-50', 'cursor-not-allowed');
            }
            document.getElementById('delete_course_id').value = courseId;
            document.getElementById('deleteModal').classList.remove('hidden');
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').classList.add('hidden');
        }

        // Close modals on outside click
        window.onclick = function(event) {
            const courseModal = document.getElementById('courseModal');
            const deleteModal = document.getElementById('deleteModal');
            if (event.target === courseModal) {
                closeModal();
            }
            if (event.target === deleteModal) {
                closeDeleteModal();
            }
        }
    </script>
</body>
</html>
