<?php
require_once 'config/db.php';
require_once 'includes/functions.php';
require_once 'includes/session.php';

// Check if user is logged in and is a company
if (!isLoggedIn() || getUserRole() !== 'company') {
    header('Location: login.php');
    exit();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $application_id = $_POST['application_id'];
    $interview_date = $_POST['interview_date'];
    $interview_time = $_POST['interview_time'];
    $interview_mode = $_POST['interview_mode'];
    $interview_location = $_POST['interview_location'];
    $interview_link = $_POST['interview_link'];
    $company_notes = $_POST['notes'];

    // Validate required fields
    if (empty($interview_date) || empty($interview_time) || empty($interview_mode)) {
        $error = "Please fill in all required fields.";
    } elseif ($interview_mode === 'onsite' && empty($interview_location)) {
        $error = "Please provide interview location for onsite interviews.";
    } elseif ($interview_mode === 'online' && empty($interview_link)) {
        $error = "Please provide interview link for online interviews.";
    } else {
        try {
            // Start transaction
            $pdo->beginTransaction();

            // Get application details
            $stmt = $pdo->prepare("
                SELECT ja.*, jl.title AS job_title, u.id as user_id, u.name AS applicant_name, jl.company_id, ja.job_id
                FROM job_applications ja
                JOIN job_listings jl ON ja.job_id = jl.id
                JOIN users u ON ja.user_id = u.id
                WHERE ja.id = ?
            ");
            $stmt->execute([$application_id]);
            $application = $stmt->fetch();

            // Insert interview schedule into interview_schedules table
            $stmt = $pdo->prepare("
                INSERT INTO interview_schedules (
                    application_id, user_id, company_id, job_id, 
                    interview_date, interview_time, interview_mode, 
                    interview_location, interview_link, status, 
                    company_notes, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'proposed', ?, NOW())
            ");
            $stmt->execute([
                $application_id,
                $application['user_id'],
                $application['company_id'],
                $application['job_id'],
                $interview_date,
                $interview_time,
                $interview_mode,
                $interview_mode === 'onsite' ? $interview_location : null,
                $interview_mode === 'online' ? $interview_link : null,
                $company_notes
            ]);

            // Update application status
            $stmt = $pdo->prepare("
                UPDATE job_applications 
                SET status = 'interview' 
                WHERE id = ?
            ");
            $stmt->execute([$application_id]);

            // Create notification for applicant
            $applicant_notification_title = "Interview Scheduled";
            $applicant_notification_message = "An interview has been scheduled for your application to {$application['job_title']} on " . 
                date('M d, Y', strtotime($interview_date)) . " at " . 
                date('h:i A', strtotime($interview_time)) . 
                ". The interview will be conducted " . 
                ($interview_mode === 'online' ? 'online' : 'at ' . $interview_location) . 
                ". " . ($company_notes ? "Additional notes: " . $company_notes : "");

            $stmt = $pdo->prepare("
                INSERT INTO notifications (user_id, title, message, type, created_at)
                VALUES (?, ?, ?, 'interview', NOW())
            ");
            $stmt->execute([$application['user_id'], $applicant_notification_title, $applicant_notification_message]);

            // Create notification for admin
            $admin_notification_title = "New Interview Schedule";
            $admin_notification_message = "A new interview has been scheduled for {$application['job_title']} with " . 
                $application['applicant_name'] . " on " . 
                date('M d, Y', strtotime($interview_date)) . " at " . 
                date('h:i A', strtotime($interview_time)) . 
                ". The interview will be conducted " . 
                ($interview_mode === 'online' ? 'online' : 'at ' . $interview_location) . 
                ". " . ($company_notes ? "Additional notes: " . $company_notes : "");

            $stmt = $pdo->prepare("
                INSERT INTO notifications (user_id, title, message, type, created_at)
                SELECT id, ?, ?, 'interview', NOW()
                FROM users 
                WHERE role = 'admin'
            ");
            $stmt->execute([$admin_notification_title, $admin_notification_message]);

            $pdo->commit();
            $success = "Interview scheduled successfully! Waiting for admin approval.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error scheduling interview: " . $e->getMessage();
        }
    }
}

// Get application details
$application_id = $_GET['application_id'] ?? null;
if (!$application_id) {
    header('Location: company_dashboard.php');
    exit();
}

$stmt = $pdo->prepare("
    SELECT ja.*, jl.title AS job_title, u.name as applicant_name 
    FROM job_applications ja 
    JOIN job_listings jl ON ja.job_id = jl.id 
    JOIN users u ON ja.user_id = u.id 
    WHERE ja.id = ?
");
$stmt->execute([$application_id]);
$application = $stmt->fetch();

if (!$application) {
    header('Location: company_dashboard.php');
    exit();
}

// Add this after the initial checks
if (isset($_GET['check_slot'])) {
    $date = $_GET['date'];
    $time = $_GET['time'];
    $company_id = $_SESSION['company_id'];
    $application_id = $_GET['application_id'] ?? null;
    
    $isAvailable = isSlotAvailable($pdo, $date, $time, $company_id, $application_id);
    $message = $isAvailable ? 'Slot is available' : 'This applicant already has a pending interview schedule';
    echo json_encode(['available' => $isAvailable, 'message' => $message]);
    exit;
}
require_once 'includes/header.php';
?>

<style>
    body {
        background: #F5F7F5;
    }
    .custom-card {
        border-radius: 18px;
        box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        border: 1px solid #BFCABF;
        background: #fff;
    }
    .custom-header {
        background-color: #E9F5E9;
        border-radius: 18px 18px 0 0;
        border-bottom: 1px solid #BFCABF;
        color: #3A463A;
        font-weight: 600;
    }
    .custom-table {
        border-radius: 12px;
        overflow: hidden;
        margin-bottom: 0;
    }
    .custom-table thead tr {
        background-color: #D6EFD6;
        color: #23422B;
        font-weight: 700;
        border-top-left-radius: 12px;
        border-top-right-radius: 12px;
    }
    .custom-table th {
        font-weight: 700;
        border: none;
        color: #23422B;
        background: transparent;
    }
    .custom-table td {
        vertical-align: middle;
        border-top: 1px solid #E9F5E9;
        border-bottom: none;
    }
    .custom-table tr:last-child td {
        border-bottom: none;
    }
    .custom-badge {
        background: #4B654F !important;
        color: #fff !important;
        border-radius: 6px;
        font-weight: 400;
        font-size: 0.82em;
        padding: 0.18em 0.7em;
        letter-spacing: 0.01em;
    }
    .custom-btn-outline {
        border: 1.5px solid #4B654F !important;
        color: #4B654F !important;
        border-radius: 8px !important;
        background: #fff !important;
        font-weight: 500;
        font-size: 1rem;
        padding: 4px 16px;
        transition: 0.2s;
    }
    .custom-btn-outline.btn-sm {
        font-size: 0.85rem;
        padding: 2px 10px;
    }
    .custom-btn-outline:hover {
        background: #E9F5E9 !important;
        color: #3A463A !important;
        border-color: #3A463A !important;
    }
    .custom-link {
        color: #4B654F;
        text-decoration: none;
        font-weight: 500;
        transition: color 0.2s, background 0.2s;
        border-radius: 4px;
        padding: 1px 3px;
    }
    .custom-link:hover, .custom-link:focus {
        color: #3A463A;
        background: #E9F5E9;
        text-decoration: none;
    }
</style>

<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card custom-card">
                <div class="card-body">
                    <h4 class="card-title custom-header">Schedule Interview</h4>
                    <h6 class="card-subtitle mb-3 text-muted">
                        Position: <?php echo htmlspecialchars($application['job_title']); ?><br>
                        Applicant: <?php echo htmlspecialchars($application['applicant_name']); ?>
                    </h6>

                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?php echo $error; ?></div>
                    <?php endif; ?>

                    <?php if ($success): ?>
                        <div class="alert alert-success"><?php echo $success; ?></div>
                    <?php endif; ?>

                    <form method="POST" action="" id="interviewForm">
                        <input type="hidden" name="application_id" value="<?php echo $application_id; ?>">

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="interview_date" class="form-label">Interview Date</label>
                                <input type="date" class="form-control" id="interview_date" name="interview_date" required>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="interview_time" class="form-label">Interview Time</label>
                                <input type="time" class="form-control" id="interview_time" name="interview_time" required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="interview_mode" class="form-label">Interview Mode</label>
                            <select class="form-select" id="interview_mode" name="interview_mode" required>
                                <option value="onsite">On-site</option>
                                <option value="online">Online</option>
                            </select>
                        </div>

                        <div class="mb-3" id="locationField">
                            <label for="interview_location" class="form-label">Interview Location</label>
                            <input type="text" class="form-control" id="interview_location" name="interview_location">
                            <div class="form-text">Required for on-site interviews</div>
                        </div>

                        <div class="mb-3" id="linkField" style="display: none;">
                            <label for="interview_link" class="form-label">Interview Link</label>
                            <input type="url" class="form-control" id="interview_link" name="interview_link">
                            <div class="form-text">Required for online interviews</div>
                        </div>

                        <div class="mb-3">
                            <label for="notes" class="form-label">Additional Notes</label>
                            <textarea class="form-control" id="notes" name="notes" rows="3"></textarea>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary">Schedule Interview</button>
                            <a href="view_applications.php" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('interview_mode').addEventListener('change', function() {
    const locationField = document.getElementById('locationField');
    const linkField = document.getElementById('linkField');
    
    if (this.value === 'onsite') {
        locationField.style.display = 'block';
        linkField.style.display = 'none';
        document.getElementById('interview_location').required = true;
        document.getElementById('interview_link').required = false;
    } else {
        locationField.style.display = 'none';
        linkField.style.display = 'block';
        document.getElementById('interview_location').required = false;
        document.getElementById('interview_link').required = true;
    }
});

document.addEventListener('DOMContentLoaded', function() {
    const dateInput = document.getElementById('interview_date');
    const timeInput = document.getElementById('interview_time');
    const submitButton = document.querySelector('button[type="submit"]');
    const timeFeedback = document.createElement('div');
    timeFeedback.className = 'invalid-feedback';
    timeInput.parentNode.appendChild(timeFeedback);
    
    function checkSlotAvailability() {
        const date = dateInput.value;
        const time = timeInput.value;
        const applicationId = document.querySelector('input[name="application_id"]').value;
        
        if (date && time) {
            fetch(`schedule_interview.php?check_slot=1&date=${date}&time=${time}&application_id=${applicationId}`)
                .then(response => response.json())
                .then(data => {
                    if (!data.available) {
                        timeInput.classList.add('is-invalid');
                        timeFeedback.textContent = data.message;
                        submitButton.disabled = true;
                    } else {
                        timeInput.classList.remove('is-invalid');
                        timeFeedback.textContent = '';
                        submitButton.disabled = false;
                    }
                });
        }
    }
    
    dateInput.addEventListener('change', checkSlotAvailability);
    timeInput.addEventListener('change', checkSlotAvailability);
});
</script>

<?php require_once 'includes/footer.php'; ?> 