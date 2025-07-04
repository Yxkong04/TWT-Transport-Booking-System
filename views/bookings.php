<?php
require_once('../includes/db.php');
require_once('header.php');
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user'])) {
    header('Location: ../public/login.php');
    exit();
}
$db = getDB();
$user_id = $_SESSION['user']['id'];
// Auto-complete past bookings
$now = date('Y-m-d H:i:s');
$stmt = $db->prepare('UPDATE bookings b JOIN schedules s ON b.schedule_id = s.id SET b.status = ? WHERE b.user_id = ? AND s.departure_time <= ? AND b.status NOT IN (?, ?)');
$stmt->execute(['Completed', $user_id, $now, 'Cancelled', 'Completed']);
$bookings = [];
$sql = 'SELECT b.booking_id, b.status, b.seat_number, b.booking_time, s.departure_time, s.route_id, s.bus_id, bu.bus_number, bu.company, r.source, r.destination
        FROM bookings b
        JOIN schedules s ON b.schedule_id = s.id
        JOIN buses bu ON s.bus_id = bu.id
        JOIN routes r ON s.route_id = r.id
        WHERE b.user_id = ?
        ORDER BY s.departure_time DESC';
$stmt = $db->prepare($sql);
$stmt->execute([$user_id]);
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_booking_id'])) {
    $cancel_id = $_POST['cancel_booking_id'];
    // Find the booking and get schedule id and seat number
    $stmt = $db->prepare('SELECT schedule_id, seat_number FROM bookings WHERE booking_id = ? AND user_id = ?');
    $stmt->execute([$cancel_id, $user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        // Set booking status to Cancelled
        $stmt = $db->prepare('UPDATE bookings SET status = ? WHERE booking_id = ? AND user_id = ?');
        $stmt->execute(['Cancelled', $cancel_id, $user_id]);
        // Increment available seats
        $stmt = $db->prepare('UPDATE schedules SET available_seats = available_seats + 1 WHERE id = ?');
        $stmt->execute([$row['schedule_id']]);
        // Refresh bookings
        header('Location: bookings.php');
        exit();
    }
}
$now = date('Y-m-d H:i:s');
$upcoming = array_filter($bookings, function($b) use ($now) { return $b['departure_time'] > $now && $b['status'] !== 'Cancelled'; });
$past = array_filter($bookings, function($b) use ($now) { return $b['departure_time'] <= $now || $b['status'] === 'Cancelled'; });
?>
<main style="display:flex;flex-direction:column;align-items:center;min-height:80vh;">
    <div class="bookings-outer-card">
        <h1 style="margin-bottom:2.5rem;"><i class="fa fa-ticket-alt icon-red"></i> My Bookings</h1>
        <!-- Upcoming Trips Section -->
        <section style="margin-bottom:2.5rem;">
            <div class="booking-section-card">
                <div class="booking-section-header"><i class="fa fa-calendar-check-o"></i> Upcoming Trips</div>
                <div class="booking-section-body">
                    <?php if (empty($upcoming)): ?>
                        <div class="alert alert-warning" style="margin-bottom:1.2rem;">No upcoming trips. Plan your next trip today.</div>
                        <a href="schedule.php" class="btn-book-ticket"><i class="fa fa-plus-circle"></i> Book Ticket Now</a>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped table-bordered upcoming-table">
                                <thead>
                                    <tr>
                                        <th>Booking ID</th>
                                        <th>Bus ID</th>
                                        <th>Company</th>
                                        <th>Route</th>
                                        <th>Departure</th>
                                        <th>Seat</th>
                                        <th>Status</th>
                                        <th>Booked At</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($upcoming as $booking): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($booking['booking_id']); ?></td>
                                        <td><?php echo htmlspecialchars($booking['bus_number']); ?></td>
                                        <td><?php echo htmlspecialchars($booking['company']); ?></td>
                                        <td><?php echo htmlspecialchars($booking['source'] . ' → ' . $booking['destination']); ?></td>
                                        <td><?php echo htmlspecialchars($booking['departure_time']); ?></td>
                                        <td><?php echo htmlspecialchars($booking['seat_number']); ?></td>
                                        <td><span class="badge bg-success">Upcoming</span></td>
                                        <td><?php echo htmlspecialchars($booking['booking_time']); ?></td>
                                        <td>
                                            <form method="post" style="display:inline;">
                                                <input type="hidden" name="cancel_booking_id" value="<?php echo htmlspecialchars($booking['booking_id']); ?>">
                                                <button type="submit" class="btn-cancel btn btn-danger" onclick="return confirm('Are you sure you want to cancel this booking?');"><i class="fa fa-times"></i> Cancel</button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
        <!-- Past Bookings Section -->
        <section>
            <div class="booking-section-card">
                <div class="booking-section-header"><i class="fa fa-history"></i> Past Bookings</div>
                <div class="booking-section-body">
                    <div style="margin-bottom:1.2rem;display:flex;gap:1rem;">
                        <button class="btn-filter btn btn-warning" onclick="filterPast('all')"><i class="fa fa-list"></i> All</button>
                        <button class="btn-filter btn btn-success" onclick="filterPast('Completed')"><i class="fa fa-check-circle"></i> Completed</button>
                        <button class="btn-filter btn btn-danger" onclick="filterPast('Cancelled')"><i class="fa fa-times-circle"></i> Cancelled</button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-striped table-bordered past-table" id="pastBookingsTable">
                            <thead>
                                <tr>
                                    <th>Booking ID</th>
                                    <th>Bus ID</th>
                                    <th>Company</th>
                                    <th>Route</th>
                                    <th>Departure</th>
                                    <th>Seat</th>
                                    <th>Status</th>
                                    <th>Booked At</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($past)): ?>
                                    <tr><td colspan="8" style="text-align:center;">No past bookings found.</td></tr>
                                <?php else: foreach ($past as $booking): ?>
                                <tr data-status="<?php echo htmlspecialchars($booking['status']); ?>">
                                    <td><?php echo htmlspecialchars($booking['booking_id']); ?></td>
                                    <td><?php echo htmlspecialchars($booking['bus_number']); ?></td>
                                    <td><?php echo htmlspecialchars($booking['company']); ?></td>
                                    <td><?php echo htmlspecialchars($booking['source'] . ' → ' . $booking['destination']); ?></td>
                                    <td><?php echo htmlspecialchars($booking['departure_time']); ?></td>
                                    <td><?php echo htmlspecialchars($booking['seat_number']); ?></td>
                                    <td>
                                        <?php if ($booking['status'] === 'Completed'): ?>
                                            <span class="badge bg-success">Completed</span>
                                        <?php elseif ($booking['status'] === 'Cancelled'): ?>
                                            <span class="badge bg-danger">Cancelled</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary"><?php echo htmlspecialchars($booking['status']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($booking['booking_time']); ?></td>
                                </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <script>
                    function filterPast(status) {
                        var rows = document.querySelectorAll('#pastBookingsTable tbody tr');
                        rows.forEach(function(row) {
                            if (status === 'all' || row.getAttribute('data-status') === status) {
                                row.style.display = '';
                            } else {
                                row.style.display = 'none';
                            }
                        });
                    }
                    </script>
                </div>
            </div>
        </section>
    </div>
</main>
<?php require_once('footer.php'); ?> 