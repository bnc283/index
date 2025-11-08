<?php
require_once '../config/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

Auth::requireRole('admin');

$conn = getDBConnection();

$conn->query("CREATE TABLE IF NOT EXISTS grading_criteria_guidelines (id INT AUTO_INCREMENT PRIMARY KEY, criteria VARCHAR(100) NOT NULL, min_weight DECIMAL(5,2) NOT NULL, max_weight DECIMAL(5,2) NOT NULL, is_active TINYINT(1) DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uq_criteria (criteria))");
$conn->query("CREATE TABLE IF NOT EXISTS grading_transmutation_table (id INT AUTO_INCREMENT PRIMARY KEY, min_percentage DECIMAL(5,2) NOT NULL, max_percentage DECIMAL(5,2) NOT NULL, equivalent_grade DECIMAL(5,2) NOT NULL, descriptive_rating VARCHAR(50) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uq_range (min_percentage, max_percentage))");

$message = null;

// CRUD handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	if (isset($_POST['apply_defaults'])) {
		// Replace current standards with the official defaults
		$conn->query("TRUNCATE TABLE grading_criteria_guidelines");
		$conn->query("TRUNCATE TABLE grading_transmutation_table");
		// Criteria guidelines per provided standard
		$defaults_criteria = [
			['Mid-Term Examinations', 20.00, 30.00],
			['Final Examinations', 30.00, 40.00],
			['Long Exams', 15.00, 30.00],
			['Assignments, Short Quizzes', 5.00, 10.00],
			['Performance Tests', 5.00, 20.00],
			['Projects', 5.00, 10.00],
			['Recitation/ Class Participation', 5.00, 15.00],
		];
		$stmt = $conn->prepare("INSERT INTO grading_criteria_guidelines (criteria, min_weight, max_weight, is_active) VALUES (?, ?, ?, 1)");
		foreach ($defaults_criteria as $row) {
			$stmt->bind_param("sdd", $row[0], $row[1], $row[2]);
			$stmt->execute();
		}
		// Transmutation rows per provided standard (percentage inclusive ranges)
		$defaults_trans = [
			[97.00, 100.00, 1.00, 'Excellent'],
			[93.00, 96.00, 1.25, 'Excellent'],
			[89.00, 92.00, 1.50, 'Highly Satisfactory'],
			[85.00, 88.00, 1.75, 'Highly Satisfactory'],
			[80.00, 84.00, 2.00, 'Satisfactory'],
			[75.00, 79.00, 2.25, 'Satisfactory'],
			[70.00, 74.00, 2.50, 'Fairly Satisfactory'],
			[65.00, 69.00, 2.75, 'Fairly Satisfactory'],
			[60.00, 64.00, 3.00, 'Passed'],
			[55.00, 59.00, 4.00, 'Condition'],
			[0.00, 0.00, 5.00, 'Failed'],
		];
		$stmt2 = $conn->prepare("INSERT INTO grading_transmutation_table (min_percentage, max_percentage, equivalent_grade, descriptive_rating) VALUES (?, ?, ?, ?)");
		foreach ($defaults_trans as $row) {
			$stmt2->bind_param("ddds", $row[0], $row[1], $row[2], $row[3]);
			$stmt2->execute();
		}
		$message = ['type' => 'success', 'text' => 'Default grading standards applied.'];
	}
	if (isset($_POST['save_guideline'])) {
		$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
		$criteria = sanitize($_POST['criteria']);
		$min = (float)$_POST['min_weight'];
		$max = (float)$_POST['max_weight'];
		$is_active = isset($_POST['is_active']) ? 1 : 0;
		if ($criteria === '' || $min < 0 || $max < 0 || $min > $max) {
			$message = ['type' => 'error', 'text' => 'Invalid criteria or weights.'];
		} else {
			if ($id > 0) {
				$stmt = $conn->prepare("UPDATE grading_criteria_guidelines SET criteria=?, min_weight=?, max_weight=?, is_active=? WHERE id=?");
				$stmt->bind_param("sddii", $criteria, $min, $max, $is_active, $id);
			} else {
				$stmt = $conn->prepare("INSERT INTO grading_criteria_guidelines (criteria, min_weight, max_weight, is_active) VALUES (?, ?, ?, ?)");
				$stmt->bind_param("sddi", $criteria, $min, $max, $is_active);
			}
			if ($stmt->execute()) {
				$message = ['type' => 'success', 'text' => 'Guideline saved.'];
			} else {
				$message = ['type' => 'error', 'text' => 'Failed to save guideline.'];
			}
		}
	} elseif (isset($_POST['delete_guideline'])) {
		$id = (int)$_POST['id'];
		$stmt = $conn->prepare("DELETE FROM grading_criteria_guidelines WHERE id = ?");
		$stmt->bind_param("i", $id);
		$message = $stmt->execute() ? ['type' => 'success', 'text' => 'Guideline deleted.'] : ['type' => 'error', 'text' => 'Delete failed.'];
    } elseif (isset($_POST['save_transmutation'])) {
		$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
		$minp = (float)$_POST['min_percentage'];
		$maxp = (float)$_POST['max_percentage'];
		$eq = (float)$_POST['equivalent_grade'];
		$desc = sanitize($_POST['descriptive_rating']);
		if ($minp < 0 || $maxp < 0 || $minp > $maxp || $desc === '') {
			$message = ['type' => 'error', 'text' => 'Invalid transmutation row.'];
		} else {
			if ($id > 0) {
				$stmt = $conn->prepare("UPDATE grading_transmutation_table SET min_percentage=?, max_percentage=?, equivalent_grade=?, descriptive_rating=? WHERE id=?");
				$stmt->bind_param("dddsi", $minp, $maxp, $eq, $desc, $id);
			} else {
				$stmt = $conn->prepare("INSERT INTO grading_transmutation_table (min_percentage, max_percentage, equivalent_grade, descriptive_rating) VALUES (?, ?, ?, ?)");
				$stmt->bind_param("ddis", $minp, $maxp, $eq, $desc);
			}
			if ($stmt->execute()) {
				$message = ['type' => 'success', 'text' => 'Transmutation row saved.'];
			} else {
				$message = ['type' => 'error', 'text' => 'Failed to save row.'];
			}
		}
	} elseif (isset($_POST['delete_transmutation'])) {
		$id = (int)$_POST['id'];
		$stmt = $conn->prepare("DELETE FROM grading_transmutation_table WHERE id = ?");
		$stmt->bind_param("i", $id);
		$message = $stmt->execute() ? ['type' => 'success', 'text' => 'Row deleted.'] : ['type' => 'error', 'text' => 'Delete failed.'];
	}
}

// Load data
$guidelines = $conn->query("SELECT * FROM grading_criteria_guidelines ORDER BY criteria")->fetch_all(MYSQLI_ASSOC);
$trans = $conn->query("SELECT * FROM grading_transmutation_table ORDER BY min_percentage DESC")->fetch_all(MYSQLI_ASSOC);

// Compute guideline totals for sanity check
$sumMin = 0; $sumMax = 0;
foreach ($guidelines as $g) { $sumMin += (float)$g['min_weight']; $sumMax += (float)$g['max_weight']; }

$unread_count = getUnreadNotificationCount($_SESSION['user_id']);

closeDBConnection($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1.0" />
	<title>Grading Standards - Admin Portal</title>
	<script src="https://cdn.tailwindcss.com"></script>
	<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-50">
	<?php include 'includes/nav.php'; ?>
	<div class="container mx-auto px-4 py-8">
		<div class="mb-6 flex items-center justify-between">
			<div>
				<h1 class="text-3xl font-bold text-gray-900">Grading Standards</h1>
				<p class="text-gray-600">Manage criteria weight guidelines and the transmutation table.</p>
			</div>
			<form method="post" action="" onsubmit="return confirmAction('Apply default standards? This will overwrite current standards.')">
				<button type="submit" name="apply_defaults" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded text-sm font-semibold">
					<i class="fas fa-rotate mr-1"></i>Apply Default Standards
				</button>
			</form>
		</div>

		<?php if ($message): ?>
			<div class="mb-4 p-3 rounded border <?php echo $message['type']==='success'?'bg-green-50 border-green-200 text-green-700':'bg-red-50 border-red-200 text-red-700'; ?>">
				<?php echo htmlspecialchars($message['text']); ?>
			</div>
		<?php endif; ?>

		<?php if (!empty($guidelines)): ?>
			<?php if ($sumMin > 100 || $sumMax < 100): ?>
				<div class="mb-4 p-3 rounded border bg-yellow-50 border-yellow-200 text-yellow-800">
					Total min weights = <?php echo number_format($sumMin,2); ?>%, total max weights = <?php echo number_format($sumMax,2); ?>%. Ensure feasible range includes 100%.
				</div>
			<?php endif; ?>
		<?php endif; ?>

		<div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
			<!-- Criteria Guidelines -->
			<div class="bg-white rounded-lg shadow overflow-hidden">
				<div class="p-6 border-b border-gray-200 flex items-center justify-between">
					<h2 class="text-lg font-bold text-gray-900"><i class="fas fa-sliders-h text-indigo-600 mr-2"></i>Criteria Weight Guidelines</h2>
					<button onclick="openGuidelineModal()" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded text-sm font-semibold">
						<i class="fas fa-plus mr-1"></i>Add Criterion
					</button>
				</div>
				<div class="p-6 overflow-x-auto">
					<table class="min-w-full divide-y divide-gray-200">
						<thead class="bg-gray-50">
							<tr>
								<th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Criteria</th>
								<th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Min %</th>
								<th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Max %</th>
								<th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Active</th>
								<th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
							</tr>
						</thead>
						<tbody class="bg-white divide-y divide-gray-200">
							<?php if (empty($guidelines)): ?>
								<tr><td colspan="5" class="px-4 py-6 text-center text-gray-500">No criteria yet.</td></tr>
							<?php else: foreach ($guidelines as $g): ?>
								<tr>
									<td class="px-4 py-2 text-sm font-medium text-gray-900"><?php echo htmlspecialchars($g['criteria']); ?></td>
									<td class="px-4 py-2 text-sm text-gray-700"><?php echo number_format($g['min_weight'], 2); ?></td>
									<td class="px-4 py-2 text-sm text-gray-700"><?php echo number_format($g['max_weight'], 2); ?></td>
									<td class="px-4 py-2 text-sm"><?php echo $g['is_active'] ? '<span class="px-2 py-1 text-xs rounded bg-green-100 text-green-800">Yes</span>' : '<span class="px-2 py-1 text-xs rounded bg-gray-100 text-gray-700">No</span>'; ?></td>
									<td class="px-4 py-2 text-right text-sm">
										<button onclick='editGuideline(<?php echo json_encode($g); ?>)' class="text-indigo-600 hover:text-indigo-800 mr-3"><i class="fas fa-edit"></i> Edit</button>
										<form method="post" action="" class="inline" onsubmit="return confirmAction('Delete this criterion?')">
											<input type="hidden" name="id" value="<?php echo (int)$g['id']; ?>" />
											<button type="submit" name="delete_guideline" class="text-red-600 hover:text-red-800"><i class="fas fa-trash"></i> Delete</button>
										</form>
									</td>
								</tr>
							<?php endforeach; endif; ?>
						</tbody>
					</table>
				</div>
			</div>

			<!-- Transmutation Table -->
			<div class="bg-white rounded-lg shadow overflow-hidden">
				<div class="p-6 border-b border-gray-200 flex items-center justify-between">
					<h2 class="text-lg font-bold text-gray-900"><i class="fas fa-arrows-down-to-people text-indigo-600 mr-2"></i>Transmutation Table</h2>
					<button onclick="openTransmutationModal()" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded text-sm font-semibold">
						<i class="fas fa-plus mr-1"></i>Add Row
					</button>
				</div>
				<div class="p-6 overflow-x-auto">
					<table class="min-w-full divide-y divide-gray-200">
						<thead class="bg-gray-50">
							<tr>
								<th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Min %</th>
								<th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Max %</th>
								<th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Equivalent</th>
								<th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
								<th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
							</tr>
						</thead>
						<tbody class="bg-white divide-y divide-gray-200">
							<?php if (empty($trans)): ?>
								<tr><td colspan="5" class="px-4 py-6 text-center text-gray-500">No rows yet.</td></tr>
							<?php else: foreach ($trans as $r): ?>
								<tr>
									<td class="px-4 py-2 text-sm text-gray-900"><?php echo number_format($r['min_percentage'], 2); ?></td>
									<td class="px-4 py-2 text-sm text-gray-900"><?php echo number_format($r['max_percentage'], 2); ?></td>
									<td class="px-4 py-2 text-sm text-gray-900"><?php echo number_format($r['equivalent_grade'], 2); ?></td>
									<td class="px-4 py-2 text-sm text-gray-700"><?php echo htmlspecialchars($r['descriptive_rating']); ?></td>
									<td class="px-4 py-2 text-right text-sm">
										<button onclick='editTransmutation(<?php echo json_encode($r); ?>)' class="text-indigo-600 hover:text-indigo-800 mr-3"><i class="fas fa-edit"></i> Edit</button>
										<form method="post" action="" class="inline" onsubmit="return confirmAction('Delete this row?')">
											<input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>" />
											<button type="submit" name="delete_transmutation" class="text-red-600 hover:text-red-800"><i class="fas fa-trash"></i> Delete</button>
										</form>
									</td>
								</tr>
							<?php endforeach; endif; ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
	</div>

<!-- Guideline Modal -->
<div id="guidelineModal" class="hidden fixed inset-0 bg-black/30 z-50">
	<div class="max-w-lg mx-auto bg-white rounded-lg shadow mt-24 p-6">
		<div class="flex items-center justify-between mb-4">
			<h3 class="text-xl font-bold text-gray-900" id="gModalTitle">Add Criterion</h3>
			<button onclick="closeGuidelineModal()" class="text-gray-500 hover:text-gray-700"><i class="fas fa-times"></i></button>
		</div>
		<form method="post" action="">
			<input type="hidden" name="id" id="g_id" />
			<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
				<div>
					<label class="block text-sm font-medium text-gray-700 mb-1">Criteria</label>
					<input type="text" name="criteria" id="g_criteria" class="w-full border-gray-300 rounded-lg" placeholder="e.g., Midterm" required />
				</div>
				<div class="flex items-center space-x-4">
					<div class="flex-1">
						<label class="block text-sm font-medium text-gray-700 mb-1">Min %</label>
						<input type="number" step="0.01" name="min_weight" id="g_min" class="w-full border-gray-300 rounded-lg" required />
					</div>
					<div class="flex-1">
						<label class="block text-sm font-medium text-gray-700 mb-1">Max %</label>
						<input type="number" step="0.01" name="max_weight" id="g_max" class="w-full border-gray-300 rounded-lg" required />
					</div>
				</div>
			</div>
			<div class="mt-3">
				<label class="inline-flex items-center space-x-2">
					<input type="checkbox" name="is_active" id="g_active" checked />
					<span class="text-sm text-gray-700">Active</span>
				</label>
			</div>
			<div class="mt-5 flex justify-end space-x-2">
				<button type="button" onclick="closeGuidelineModal()" class="px-4 py-2 border rounded">Cancel</button>
				<button type="submit" name="save_guideline" class="px-4 py-2 rounded bg-indigo-600 hover:bg-indigo-700 text-white">Save</button>
			</div>
		</form>
	</div>
</div>

<!-- Transmutation Modal -->
<div id="transModal" class="hidden fixed inset-0 bg-black/30 z-50">
	<div class="max-w-lg mx-auto bg-white rounded-lg shadow mt-24 p-6">
		<div class="flex items-center justify-between mb-4">
			<h3 class="text-xl font-bold text-gray-900" id="tModalTitle">Add Row</h3>
			<button onclick="closeTransmutationModal()" class="text-gray-500 hover:text-gray-700"><i class="fas fa-times"></i></button>
		</div>
		<form method="post" action="">
			<input type="hidden" name="id" id="t_id" />
			<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
				<div>
					<label class="block text-sm font-medium text-gray-700 mb-1">Min %</label>
					<input type="number" step="0.01" name="min_percentage" id="t_min" class="w-full border-gray-300 rounded-lg" required />
				</div>
				<div>
					<label class="block text-sm font-medium text-gray-700 mb-1">Max %</label>
					<input type="number" step="0.01" name="max_percentage" id="t_max" class="w-full border-gray-300 rounded-lg" required />
				</div>
				<div>
					<label class="block text-sm font-medium text-gray-700 mb-1">Equivalent</label>
					<input type="number" step="0.01" name="equivalent_grade" id="t_eq" class="w-full border-gray-300 rounded-lg" required />
				</div>
				<div>
					<label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
					<input type="text" name="descriptive_rating" id="t_desc" class="w-full border-gray-300 rounded-lg" required />
				</div>
			</div>
			<div class="mt-5 flex justify-end space-x-2">
				<button type="button" onclick="closeTransmutationModal()" class="px-4 py-2 border rounded">Cancel</button>
				<button type="submit" name="save_transmutation" class="px-4 py-2 rounded bg-indigo-600 hover:bg-indigo-700 text-white">Save</button>
			</div>
		</form>
	</div>
</div>

<script>
<?php if ($message): ?>
try { showToast(<?php echo json_encode($message['text']); ?>, <?php echo json_encode($message['type']); ?>); } catch(e) {}
<?php endif; ?>
function confirmAction(msg){ return confirm(msg); }

function openGuidelineModal(){ document.getElementById('gModalTitle').textContent='Add Criterion'; document.getElementById('g_id').value=''; document.getElementById('g_criteria').value=''; document.getElementById('g_min').value=''; document.getElementById('g_max').value=''; document.getElementById('g_active').checked=true; document.getElementById('guidelineModal').classList.remove('hidden'); }
function closeGuidelineModal(){ document.getElementById('guidelineModal').classList.add('hidden'); }
function editGuideline(g){ document.getElementById('gModalTitle').textContent='Edit Criterion'; document.getElementById('g_id').value=g.id; document.getElementById('g_criteria').value=g.criteria; document.getElementById('g_min').value=g.min_weight; document.getElementById('g_max').value=g.max_weight; document.getElementById('g_active').checked = g.is_active==1; document.getElementById('guidelineModal').classList.remove('hidden'); }

function openTransmutationModal(){ document.getElementById('tModalTitle').textContent='Add Row'; document.getElementById('t_id').value=''; document.getElementById('t_min').value=''; document.getElementById('t_max').value=''; document.getElementById('t_eq').value=''; document.getElementById('t_desc').value=''; document.getElementById('transModal').classList.remove('hidden'); }
function closeTransmutationModal(){ document.getElementById('transModal').classList.add('hidden'); }
function editTransmutation(r){ document.getElementById('tModalTitle').textContent='Edit Row'; document.getElementById('t_id').value=r.id; document.getElementById('t_min').value=r.min_percentage; document.getElementById('t_max').value=r.max_percentage; document.getElementById('t_eq').value=r.equivalent_grade; document.getElementById('t_desc').value=r.descriptive_rating; document.getElementById('transModal').classList.remove('hidden'); }
</script>

</body>
</html>


