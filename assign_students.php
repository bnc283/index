<?php
require_once '../config/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

Auth::requireRole('admin');

$conn = getDBConnection();

$message = null;

// Load classes for selection
$classes = $conn->query("SELECT c.class_id, c.class_code, c.semester, c.academic_year, co.course_code, co.course_name, i.instructor_id, u.first_name, u.last_name FROM classes c JOIN courses co ON c.course_id = co.course_id JOIN instructors i ON c.instructor_id = i.instructor_id JOIN users u ON i.user_id = u.user_id ORDER BY c.academic_year DESC, c.semester, co.course_code")->fetch_all(MYSQLI_ASSOC);

// Blocks and sections are assumed to be modeled as combinations of program/year_level and an optional 'section' column. If 'section' doesn't exist, we filter by program/year_level only.

// Load distinct programs, year levels, and sections (if present)
$programs = array_column($conn->query("SELECT DISTINCT program FROM students WHERE program IS NOT NULL AND program <> '' ORDER BY program")->fetch_all(MYSQLI_ASSOC), 'program');
$years = array_column($conn->query("SELECT DISTINCT year_level FROM students WHERE year_level IS NOT NULL ORDER BY year_level")->fetch_all(MYSQLI_ASSOC), 'year_level');

// Detect section column
$has_section = false;
$sec_check = $conn->query("SHOW COLUMNS FROM students LIKE 'section'");
if ($sec_check && $sec_check->num_rows > 0) { $has_section = true; }
$sections = $has_section ? array_column($conn->query("SELECT DISTINCT section FROM students WHERE section IS NOT NULL AND section <> '' ORDER BY section")->fetch_all(MYSQLI_ASSOC), 'section') : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$class_id = isset($_POST['class_id']) ? (int)$_POST['class_id'] : 0;
	$mode = $_POST['mode'] ?? 'block'; // block | section | name

	if ($class_id <= 0) {
		$message = ['type' => 'error', 'text' => 'Please select a class.'];
	} else {
		// Verify class and get instructor user_id
		$stmt = $conn->prepare("SELECT c.class_id, c.class_code, c.semester, c.academic_year, co.course_code, co.course_name, i.instructor_id, u.user_id AS instructor_user_id FROM classes c JOIN courses co ON c.course_id = co.course_id JOIN instructors i ON c.instructor_id = i.instructor_id JOIN users u ON i.user_id = u.user_id WHERE c.class_id = ?");
		$stmt->bind_param("i", $class_id);
		$stmt->execute();
		$class = $stmt->get_result()->fetch_assoc();
		if (!$class) {
			$message = ['type' => 'error', 'text' => 'Invalid class selected.'];
		} else {
			$target_students = [];
			if ($mode === 'block') {
				$program = trim($_POST['program'] ?? '');
				$year_level = isset($_POST['year_level']) ? (int)$_POST['year_level'] : 0;
				if ($program === '' || $year_level <= 0) {
					$message = ['type' => 'error', 'text' => 'Select program and year level.'];
				} else {
					$stmt = $conn->prepare("SELECT student_id, user_id FROM students s JOIN users u ON s.user_id = u.user_id WHERE s.program = ? AND s.year_level = ?");
					$stmt->bind_param("si", $program, $year_level);
					$stmt->execute();
					$target_students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
				}
			} elseif ($mode === 'section' && $has_section) {
				$section = trim($_POST['section'] ?? '');
				if ($section === '') {
					$message = ['type' => 'error', 'text' => 'Select a section.'];
				} else {
					$stmt = $conn->prepare("SELECT student_id, user_id FROM students s JOIN users u ON s.user_id = u.user_id WHERE s.section = ?");
					$stmt->bind_param("s", $section);
					$stmt->execute();
					$target_students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
				}
			} elseif ($mode === 'name') {
				$q = trim($_POST['student_query'] ?? '');
				if ($q === '') {
					$message = ['type' => 'error', 'text' => 'Enter a name to search.'];
				} else {
					$like = '%' . $q . '%';
					$stmt = $conn->prepare("SELECT s.student_id, u.user_id FROM students s JOIN users u ON s.user_id = u.user_id WHERE CONCAT(u.first_name, ' ', u.last_name) LIKE ? OR u.last_name LIKE ? OR u.first_name LIKE ?");
					$stmt->bind_param("sss", $like, $like, $like);
					$stmt->execute();
					$target_students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
				}
			}

			if ($message === null) {
				$added = 0; $skipped = 0;
				foreach ($target_students as $st) {
					$sid = (int)$st['student_id'];
					// already enrolled?
					$chk = $conn->prepare("SELECT enrollment_id FROM enrollments WHERE student_id = ? AND class_id = ?");
					$chk->bind_param("ii", $sid, $class_id);
					$chk->execute();
					if ($chk->get_result()->fetch_assoc()) { $skipped++; continue; }
					$ins = $conn->prepare("INSERT INTO enrollments (student_id, class_id, status) VALUES (?, ?, 'enrolled')");
					$ins->bind_param("ii", $sid, $class_id);
					if ($ins->execute()) {
						$added++;
						// notify student
						$title = 'Enrolled to ' . $class['course_code'] . ' - ' . $class['class_code'];
						$msg = 'You have been enrolled in ' . $class['course_name'] . ' (' . $class['semester'] . ' ' . $class['academic_year'] . ').';
						createNotification((int)$st['user_id'], $title, $msg, 'info', 'class', $class_id);
					}
				}
				// notify instructor
				$ititle = 'Student list updated for ' . $class['course_code'] . ' - ' . $class['class_code'];
				$imsg = 'Admin assigned ' . $added . ' students' . ($skipped ? (' (' . $skipped . ' skipped)') : '') . '.';
				createNotification((int)$class['instructor_user_id'], $ititle, $imsg, 'success', 'class', $class_id);
				$message = ['type' => 'success', 'text' => $added . ' students enrolled. ' . ($skipped ? ($skipped . ' skipped (already enrolled).') : '')];
			}
		}
	}
}

closeDBConnection($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Assign Students - Admin Portal</title>
	<script src="https://cdn.tailwindcss.com"></script>
	<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-50">
	<?php include 'includes/nav.php'; ?>

	<div class="container mx-auto px-4 py-8 max-w-4xl">
		<div class="mb-6 flex items-center justify-between">
			<div>
				<h1 class="text-3xl font-bold text-gray-900">Assign Students to Class</h1>
				<p class="text-gray-600 mt-1">Enroll by block (program + year), by section, or search by name.</p>
			</div>
			<a href="manage_courses.php" class="inline-flex items-center text-sm font-medium text-indigo-600 hover:text-indigo-800"><i class="fas fa-arrow-left mr-2"></i>Back</a>
		</div>

		<?php if ($message): ?>
			<div class="mb-6 p-4 rounded-lg <?php echo $message['type'] === 'success' ? 'bg-green-50 border border-green-200 text-green-700' : 'bg-red-50 border border-red-200 text-red-700'; ?>">
				<?php echo $message['text']; ?>
			</div>
		<?php endif; ?>

		<div class="bg-white rounded-lg shadow overflow-hidden">
			<div class="p-6 border-b border-gray-200">
				<h2 class="text-lg font-bold text-gray-900"><i class="fas fa-list-ol text-indigo-600 mr-2"></i>Enrollment Options</h2>
			</div>
			<form method="post" class="p-6 space-y-6">
				<div>
					<label class="block text-sm font-medium text-gray-700 mb-1">Class</label>
					<select name="class_id" class="w-full border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500" required>
						<option value="">Select a class</option>
						<?php foreach ($classes as $c): ?>
							<option value="<?php echo (int)$c['class_id']; ?>"><?php echo htmlspecialchars($c['course_code'] . ' - ' . $c['class_code'] . ' (' . $c['semester'] . ' ' . $c['academic_year'] . ') • ' . $c['first_name'] . ' ' . $c['last_name']); ?></option>
						<?php endforeach; ?>
					</select>
				</div>

				<div>
					<p class="text-sm font-semibold text-gray-900 mb-2">Mode</p>
					<div class="grid grid-cols-1 md:grid-cols-3 gap-4">
						<label class="border rounded-lg p-3 flex items-center space-x-2 cursor-pointer">
							<input type="radio" name="mode" value="block" checked>
							<span class="text-sm">By Block (Program + Year)</span>
						</label>
						<label class="border rounded-lg p-3 flex items-center space-x-2 cursor-pointer <?php echo $has_section ? '' : 'opacity-50 cursor-not-allowed'; ?>">
							<input type="radio" name="mode" value="section" <?php echo $has_section ? '' : 'disabled'; ?>>
							<span class="text-sm">By Section</span>
						</label>
						<label class="border rounded-lg p-3 flex items-center space-x-2 cursor-pointer">
							<input type="radio" name="mode" value="name">
							<span class="text-sm">By Name</span>
						</label>
					</div>
				</div>

				<!-- Block -->
				<div id="blockFields" class="grid grid-cols-1 md:grid-cols-2 gap-4">
					<div>
						<label class="block text-sm font-medium text-gray-700 mb-1">Program</label>
						<select name="program" class="w-full border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500">
							<option value="">Select program</option>
							<?php foreach ($programs as $p): ?>
								<option value="<?php echo htmlspecialchars($p); ?>"><?php echo htmlspecialchars($p); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div>
						<label class="block text-sm font-medium text-gray-700 mb-1">Year Level</label>
						<select name="year_level" class="w-full border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500">
							<option value="">Select year level</option>
							<?php foreach ($years as $y): ?>
								<option value="<?php echo (int)$y; ?>"><?php echo (int)$y; ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>

				<!-- Section -->
				<?php if ($has_section): ?>
				<div id="sectionFields" class="grid grid-cols-1 md:grid-cols-2 gap-4 hidden">
					<div>
						<label class="block text-sm font-medium text-gray-700 mb-1">Section</label>
						<select name="section" class="w-full border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500">
							<option value="">Select section</option>
							<?php foreach ($sections as $s): ?>
								<option value="<?php echo htmlspecialchars($s); ?>"><?php echo htmlspecialchars($s); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>
				<?php endif; ?>

				<!-- Name search -->
				<div id="nameFields" class="hidden">
					<label class="block text-sm font-medium text-gray-700 mb-1">Search Student</label>
					<input type="text" name="student_query" placeholder="Enter name (first or last)" class="w-full border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500" />
					<p class="text-xs text-gray-500 mt-1">We will add all matches found.</p>
				</div>

				<div class="flex items-center justify-end space-x-2">
					<button type="submit" class="px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white font-semibold">
						<i class="fas fa-user-plus mr-2"></i>Assign Students
					</button>
				</div>
			</form>
		</div>
	</div>

	<script>
	(function(){
		const radios = document.querySelectorAll('input[name="mode"]');
		const block = document.getElementById('blockFields');
		const section = document.getElementById('sectionFields');
		const nameF = document.getElementById('nameFields');
		function update(){
			const val = document.querySelector('input[name="mode"]:checked').value;
			block.classList.toggle('hidden', val !== 'block');
			if (section) section.classList.toggle('hidden', val !== 'section');
			nameF.classList.toggle('hidden', val !== 'name');
		}
		radios.forEach(r => r.addEventListener('change', update));
		update();
	})();
	</script>
</body>
</html>


