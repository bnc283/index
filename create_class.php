<?php
require_once '../config/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

Auth::requireRole('admin');

$conn = getDBConnection();

// Prefill course if provided
$prefill_course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;

// Load selects
$courses = $conn->query("SELECT course_id, course_code, course_name FROM courses ORDER BY course_code")->fetch_all(MYSQLI_ASSOC);
$instructors = $conn->query("SELECT i.instructor_id, u.first_name, u.last_name, u.email FROM instructors i JOIN users u ON i.user_id = u.user_id ORDER BY u.last_name, u.first_name")->fetch_all(MYSQLI_ASSOC);
$grading_systems = $conn->query("SELECT grading_system_id, name FROM grading_systems ORDER BY name")->fetch_all(MYSQLI_ASSOC);

// For optional enrollment on create
$programs = array_column($conn->query("SELECT DISTINCT program FROM students WHERE program IS NOT NULL AND program <> '' ORDER BY program")->fetch_all(MYSQLI_ASSOC), 'program');
$years = array_column($conn->query("SELECT DISTINCT year_level FROM students WHERE year_level IS NOT NULL ORDER BY year_level")->fetch_all(MYSQLI_ASSOC), 'year_level');
$has_section = false;
$sec_check = $conn->query("SHOW COLUMNS FROM students LIKE 'section'");
if ($sec_check && $sec_check->num_rows > 0) { $has_section = true; }
$sections = $has_section ? array_column($conn->query("SELECT DISTINCT section FROM students WHERE section IS NOT NULL AND section <> '' ORDER BY section")->fetch_all(MYSQLI_ASSOC), 'section') : [];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$course_id = isset($_POST['course_id']) ? (int)$_POST['course_id'] : 0;
	$instructor_id = isset($_POST['instructor_id']) ? (int)$_POST['instructor_id'] : 0;
	$grading_system_id = isset($_POST['grading_system_id']) ? (int)$_POST['grading_system_id'] : 0;
// class_code will be generated automatically
	$semester = sanitize($_POST['semester'] ?? '');
	$academic_year = sanitize($_POST['academic_year'] ?? '');
	$schedule = sanitize($_POST['schedule'] ?? '');
	$room = sanitize($_POST['room'] ?? '');
	$max_students = isset($_POST['max_students']) ? (int)$_POST['max_students'] : 40;

	if ($course_id <= 0) $errors[] = 'Select a course.';
	if ($instructor_id <= 0) $errors[] = 'Select an instructor.';
	if ($grading_system_id <= 0) $errors[] = 'Select a grading system.';
	if ($semester === '') $errors[] = 'Semester is required.';
	if ($academic_year === '') $errors[] = 'Academic year is required.';
	if ($max_students <= 0) $errors[] = 'Max students must be greater than 0.';

    if (!$errors) {
        // Validate selected grading system totals equal 100%
        $sumStmt = $conn->prepare("SELECT COALESCE(SUM(weight),0) AS total FROM grading_criteria WHERE grading_system_id = ?");
        $sumStmt->bind_param("i", $grading_system_id);
        $sumStmt->execute();
        $totalRow = $sumStmt->get_result()->fetch_assoc();
        $totalWeight = (float)$totalRow['total'];
        if (abs($totalWeight - 100.0) > 0.001) {
            $errors[] = 'Selected grading system weights must total 100%. Current total: ' . number_format($totalWeight, 2) . '%';
        }
    }

    if (!$errors) {
		$check = $conn->prepare("SELECT 1 FROM classes WHERE class_code = ? LIMIT 1");
		$check->bind_param("s", $class_code);
		$check->execute();
		if ($check->get_result()->num_rows > 0) {
			$errors[] = 'Class code already exists.';
		} else {
            // Use a single schedule and room (no separate lab schedule)
            $final_schedule = $schedule;
            $final_room = $room;
            // Insert with a temporary unique code, then set class_code to the auto-increment id
            $temp_code = 'P' . uniqid();
            $insert = $conn->prepare("INSERT INTO classes (course_id, instructor_id, grading_system_id, class_code, semester, academic_year, schedule, room, max_students, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')");
            $insert->bind_param("iiisssssi", $course_id, $instructor_id, $grading_system_id, $temp_code, $semester, $academic_year, $final_schedule, $final_room, $max_students);
			if ($insert->execute()) {
				$class_id = $insert->insert_id;
                // Set simple auto-incremental class code equal to class_id (or zero-padded if preferred)
                $class_code_gen = (string)$class_id;
                $upd = $conn->prepare("UPDATE classes SET class_code = ? WHERE class_id = ?");
                $upd->bind_param("si", $class_code_gen, $class_id);
                $upd->execute();
				// Notify instructor
                $u = $conn->query("SELECT u.user_id FROM instructors i JOIN users u ON i.user_id = u.user_id WHERE i.instructor_id = " . (int)$instructor_id)->fetch_assoc();
                if ($u) {
                    $title = 'Assigned to class ' . $class_code_gen;
                    $msg = 'You have been assigned to class ' . $class_code_gen . ' (' . $semester . ' ' . $academic_year . ').';
                    createNotification((int)$u['user_id'], $title, $msg, 'success', 'class', $class_id);
                }

                // Immediate enrollment (required)
                $enroll_mode = $_POST['enroll_mode'] ?? '';
                if (!in_array($enroll_mode, ['block','section','name'])) {
                    $errors[] = 'Please choose how to assign students (by block, section, or name).';
                }
                if (empty($errors) && in_array($enroll_mode, ['block','section','name'])) {
                    $target_students = [];
                    if ($enroll_mode === 'block') {
                        $sel_program = trim($_POST['program'] ?? '');
                        $sel_year = isset($_POST['year_level']) ? (int)$_POST['year_level'] : 0;
                        if ($sel_program === '' || $sel_year <= 0) {
                            $errors[] = 'Select program and year level to assign by block.';
                        } else {
                            $stmt = $conn->prepare("SELECT s.student_id, u.user_id FROM students s JOIN users u ON s.user_id = u.user_id WHERE s.program = ? AND s.year_level = ?");
                            $stmt->bind_param("si", $sel_program, $sel_year);
                            $stmt->execute();
                            $target_students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                        }
                    } elseif ($enroll_mode === 'section' && $has_section) {
                        $sel_section = trim($_POST['section'] ?? '');
                        if ($sel_section === '') {
                            $errors[] = 'Select a section to assign by section.';
                        } else {
                            $stmt = $conn->prepare("SELECT s.student_id, u.user_id FROM students s JOIN users u ON s.user_id = u.user_id WHERE s.section = ?");
                            $stmt->bind_param("s", $sel_section);
                            $stmt->execute();
                            $target_students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                        }
                    } elseif ($enroll_mode === 'name') {
                        $q = trim($_POST['student_query'] ?? '');
                        if ($q === '') {
                            $errors[] = 'Enter a name to search when assigning by name.';
                        } else {
                            $like = '%' . $q . '%';
                            $stmt = $conn->prepare("SELECT s.student_id, u.user_id FROM students s JOIN users u ON s.user_id = u.user_id WHERE CONCAT(u.first_name, ' ', u.last_name) LIKE ? OR u.last_name LIKE ? OR u.first_name LIKE ?");
                            $stmt->bind_param("sss", $like, $like, $like);
                            $stmt->execute();
                            $target_students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                        }
                    }

                    if (empty($errors) && !empty($target_students)) {
                        $added = 0; $skipped = 0;
                        foreach ($target_students as $st) {
                            $sid = (int)$st['student_id'];
                            $chk = $conn->prepare("SELECT enrollment_id FROM enrollments WHERE student_id = ? AND class_id = ?");
                            $chk->bind_param("ii", $sid, $class_id);
                            $chk->execute();
                            if ($chk->get_result()->fetch_assoc()) { $skipped++; continue; }
                            $ins = $conn->prepare("INSERT INTO enrollments (student_id, class_id, status) VALUES (?, ?, 'enrolled')");
                            $ins->bind_param("ii", $sid, $class_id);
                            if ($ins->execute()) {
                                $added++;
                                // notify student
                                $st_uid = (int)$st['user_id'];
                                $st_title = 'Enrolled to ' . $class_code_gen;
                                $st_msg = 'You have been enrolled in ' . $class_code_gen . ' (' . $semester . ' ' . $academic_year . ').';
                                createNotification($st_uid, $st_title, $st_msg, 'info', 'class', $class_id);
                            }
                        }
                        // notify instructor about list
                        if ($u) {
                            $ititle = 'Student list set for ' . $class_code_gen;
                            $imsg = 'Admin enrolled ' . $added . ' student(s)' . ($skipped ? (' (' . $skipped . ' skipped)') : '') . '.';
                            createNotification((int)$u['user_id'], $ititle, $imsg, 'success', 'class', $class_id);
                        }
                    } elseif (empty($errors) && empty($target_students)) {
                        $errors[] = 'No matching students found for the selected criteria.';
                    }
                }
                if (empty($errors)) {
                    // Redirect to enrollment assignment for further adjustments
                    header('Location: assign_students.php?class_id=' . $class_id);
                    exit;
                }
			} else {
				$errors[] = 'Failed to create class.';
			}
		}
	}
}

$academic_years = getAcademicYears();
$semesters = getSemesters();

closeDBConnection($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Create Class - Admin Portal</title>
	<script src="https://cdn.tailwindcss.com"></script>
	<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-50">
	<?php include 'includes/nav.php'; ?>

	<div class="container mx-auto px-4 py-8 max-w-4xl">
		<div class="mb-6 flex items-center justify-between">
			<div>
				<h1 class="text-3xl font-bold text-gray-900">Create Class</h1>
				<p class="text-gray-600 mt-1">Assign instructor, schedule, and room for this class.</p>
			</div>
			<a href="manage_courses.php" class="inline-flex items-center text-sm font-medium text-indigo-600 hover:text-indigo-800">
				<i class="fas fa-arrow-left mr-2"></i>
				Back
			</a>
		</div>

		<?php if (!empty($errors)): ?>
			<div class="mb-6 p-4 rounded-lg border border-red-200 bg-red-50 text-sm text-red-800">
				<ul class="list-disc ml-5">
					<?php foreach ($errors as $e): ?>
						<li><?php echo htmlspecialchars($e); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<form method="post" class="bg-white rounded-lg shadow overflow-hidden">
			<div class="p-6 border-b border-gray-200">
				<h2 class="text-lg font-bold text-gray-900 flex items-center">
					<i class="fas fa-chalkboard text-indigo-600 mr-2"></i>Class Details
				</h2>
			</div>
			<div class="p-6 space-y-4">
				<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
					<div>
						<label class="block text-sm font-medium text-gray-700 mb-1">Course</label>
						<select name="course_id" class="w-full border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500" required>
							<option value="">Select a course</option>
							<?php foreach ($courses as $c): ?>
								<option value="<?php echo (int)$c['course_id']; ?>" <?php echo $prefill_course_id === (int)$c['course_id'] ? 'selected' : ''; ?>>
									<?php echo htmlspecialchars($c['course_code'] . ' - ' . $c['course_name']); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
					<div>
						<label class="block text-sm font-medium text-gray-700 mb-1">Instructor</label>
						<select name="instructor_id" class="w-full border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500" required>
							<option value="">Select instructor</option>
							<?php foreach ($instructors as $i): ?>
								<option value="<?php echo (int)$i['instructor_id']; ?>"><?php echo htmlspecialchars($i['last_name'] . ', ' . $i['first_name'] . ' (' . $i['email'] . ')'); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>

				<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
					<div>
						<label class="block text-sm font-medium text-gray-700 mb-1">Grading System</label>
						<select name="grading_system_id" class="w-full border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500" required>
							<option value="">Select grading system</option>
							<?php foreach ($grading_systems as $g): ?>
								<option value="<?php echo (int)$g['grading_system_id']; ?>"><?php echo htmlspecialchars($g['name']); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Class Code (auto-generated)</label>
                        <input type="text" id="class_code_preview" value="Assigned after creation" class="w-full border-gray-300 rounded-lg bg-gray-50" readonly />
                    </div>
				</div>

				<!-- Class type -->
				<div>
					<label class="block text-sm font-medium text-gray-700 mb-2">Class Type</label>
					<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
						<label class="border rounded-lg p-3 flex items-center space-x-2 cursor-pointer">
							<input type="radio" name="class_type" value="non_lab" checked>
							<span class="text-sm">Non-laboratory (Lecture only)</span>
						</label>
						<label class="border rounded-lg p-3 flex items-center space-x-2 cursor-pointer">
							<input type="radio" name="class_type" value="with_lab">
							<span class="text-sm">With Laboratory</span>
						</label>
					</div>
				</div>

				<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
					<div>
						<label class="block text-sm font-medium text-gray-700 mb-1">Semester</label>
						<select name="semester" class="w-full border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500" required>
							<option value="">Select semester</option>
							<?php foreach ($semesters as $s): ?>
								<option value="<?php echo htmlspecialchars($s); ?>"><?php echo htmlspecialchars($s); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div>
						<label class="block text-sm font-medium text-gray-700 mb-1">Academic Year</label>
						<select name="academic_year" class="w-full border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500" required>
							<option value="">Select year</option>
							<?php foreach ($academic_years as $y): ?>
								<option value="<?php echo htmlspecialchars($y); ?>"><?php echo htmlspecialchars($y); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>

				<!-- Schedule builder -->
				<div>
					<label class="block text-sm font-medium text-gray-700 mb-2">Schedule</label>
					<div class="space-y-4">
						<div>
							<p class="text-xs text-gray-500 mb-1">Select day(s) of the week</p>
							<div class="grid grid-cols-2 sm:grid-cols-4 md:grid-cols-7 gap-2">
								<?php $days = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun']; foreach ($days as $d): ?>
									<label class="inline-flex items-center space-x-2 bg-gray-50 border border-gray-200 rounded-lg px-3 py-2 cursor-pointer hover:bg-white">
										<input type="checkbox" class="day-checkbox" value="<?php echo $d; ?>">
										<span class="text-sm text-gray-700"><?php echo $d; ?></span>
									</label>
								<?php endforeach; ?>
							</div>
						</div>
						<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
							<div>
								<label class="block text-sm font-medium text-gray-700 mb-1">Start Time</label>
								<input type="time" class="w-full border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500" id="startTime">
							</div>
							<div>
								<label class="block text-sm font-medium text-gray-700 mb-1">End Time</label>
								<input type="time" class="w-full border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500" id="endTime">
							</div>
						</div>
						<div>
							<label class="block text-sm font-medium text-gray-700 mb-1">Generated Schedule</label>
							<input type="text" name="schedule" id="scheduleField" placeholder="e.g., Mon/Wed 10:00-11:30 AM" class="w-full border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500 bg-gray-50" readonly />
							<p class="text-xs text-gray-500 mt-1">Auto-filled from your selections above.</p>
						</div>
						<div id="scheduleHint" class="text-sm text-gray-600"></div>
					</div>
				</div>

				<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
					<div>
						<label class="block text-sm font-medium text-gray-700 mb-1">Room</label>
						<input type="text" name="room" placeholder="e.g., Room 204" class="w-full border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500" />
					</div>
					<div>
						<label class="block text-sm font-medium text-gray-700 mb-1">Max Students</label>
						<input type="number" name="max_students" value="40" min="1" class="w-full border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500" required />
					</div>
				</div>

				<!-- Lab schedule removed per requirement: single schedule only -->

				<!-- Assign students now (required) -->
				<div class="mt-4">
					<h3 class="text-md font-bold text-gray-900 mb-2"><i class="fas fa-user-plus text-indigo-600 mr-2"></i>Assign Students</h3>
					<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-3">
						<label class="border rounded-lg p-3 flex items-center space-x-2 cursor-pointer">
							<input type="radio" name="enroll_mode" value="block" checked>
							<span class="text-sm">By Block (Program + Year)</span>
						</label>
						<label class="border rounded-lg p-3 flex items-center space-x-2 cursor-pointer <?php echo $has_section ? '' : 'opacity-50 cursor-not-allowed'; ?>">
							<input type="radio" name="enroll_mode" value="section" <?php echo $has_section ? '' : 'disabled'; ?>>
							<span class="text-sm">By Section</span>
						</label>
						<label class="border rounded-lg p-3 flex items-center space-x-2 cursor-pointer">
							<input type="radio" name="enroll_mode" value="name">
							<span class="text-sm">By Name</span>
						</label>
					</div>

					<div id="assignBlock" class="grid grid-cols-1 md:grid-cols-2 gap-4 hidden">
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

					<?php if ($has_section): ?>
					<div id="assignSection" class="grid grid-cols-1 md:grid-cols-2 gap-4 hidden">
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

					<div id="assignName" class="hidden">
						<label class="block text-sm font-medium text-gray-700 mb-1">Search Student by Name</label>
						<input type="text" name="student_query" placeholder="Enter name (first or last)" class="w-full border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500" />
						<p class="text-xs text-gray-500 mt-1">All matching students will be enrolled.</p>
					</div>
				</div>
			</div>
			<div class="px-6 py-4 bg-gray-50 border-t border-gray-200 flex items-center justify-end space-x-2">
				<a href="manage_courses.php" class="px-4 py-2 rounded-lg border border-gray-300 text-gray-700 hover:bg-white">Cancel</a>
				<button type="submit" class="px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white font-semibold">
					<i class="fas fa-plus mr-2"></i>Create Class
				</button>
			</div>
		</form>
	</div>

	<script>
	(function(){
		const days = Array.from(document.querySelectorAll('.day-checkbox'));
		const startTime = document.getElementById('startTime');
		const endTime = document.getElementById('endTime');
		const scheduleField = document.getElementById('scheduleField');
		const hint = document.getElementById('scheduleHint');
		function pad(n){ return (n+'').padStart(2,'0'); }
		function to12h(t){ if(!t) return ''; const [h,m]=t.split(':').map(Number); const ampm=h>=12?'PM':'AM'; const h12=h%12===0?12:h%12; return `${h12}:${pad(m)} ${ampm}`; }
		function compose(){
			const selectedDays = days.filter(d=>d.checked).map(d=>d.value);
			const st = startTime.value; const et = endTime.value;
			let parts=[]; if(selectedDays.length) parts.push(selectedDays.join('/')); if(st&&et) parts.push(`${to12h(st)}-${to12h(et)}`);
			scheduleField.value = parts.join(' ');
			let issues=[]; if(!selectedDays.length) issues.push('Select day(s)'); if(!st||!et) issues.push('Choose time'); if(st&&et&&st>=et) issues.push('End time after start time');
			hint.textContent = issues.length?('Tip: '+issues.join(' • ')):'';
		}
		[...days,startTime,endTime].forEach(el=>{ if(!el) return; el.addEventListener('change',compose); el.addEventListener('input',compose); });
		compose();

		// Toggle assign modes
		const modeRadios = document.querySelectorAll('input[name="enroll_mode"]');
		const assignBlock = document.getElementById('assignBlock');
		const assignSection = document.getElementById('assignSection');
		const assignName = document.getElementById('assignName');
		function updateAssign(){
			const val = document.querySelector('input[name="enroll_mode"]:checked').value;
			assignBlock && assignBlock.classList.toggle('hidden', val !== 'block');
			assignSection && assignSection.classList.toggle('hidden', val !== 'section');
			assignName && assignName.classList.toggle('hidden', val !== 'name');
		}
		modeRadios.forEach(r => r.addEventListener('change', updateAssign));
		updateAssign();

		// Prefill class type from query param if provided
		// class_type param no longer toggles any schedule UI (single schedule only)

		// Auto-generate class code preview when selections change
		const courseSelect = document.querySelector('select[name="course_id"]');
		const semSelect = document.querySelector('select[name="semester"]');
		const aySelect = document.querySelector('select[name="academic_year"]');
		const codePreview = document.getElementById('class_code_preview');
		function genCode(){
			const courseText = courseSelect ? courseSelect.options[courseSelect.selectedIndex]?.text || '' : '';
			const courseCode = courseText.split(' - ')[0] || 'CLS';
			const ay = aySelect?.value || '';
			if (!courseCode || !ay) { codePreview.value = ''; return; }
			const ayCompact = (ay || '').replace(/[^0-9-]/g,'');
			codePreview.value = `${courseCode}-${ayCompact}-?`;
		}
		[courseSelect, semSelect, aySelect].forEach(el => { if (el) { el.addEventListener('change', genCode); el.addEventListener('input', genCode); } });
		genCode();
	})();
	</script>
</body>
</html>


