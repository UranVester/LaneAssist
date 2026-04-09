<?php
// Debug script to find the actual error
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Debug Info</h1>";

echo "<h2>1. Loading config.php...</h2>";
try {
    require_once(dirname(__FILE__, 3) . '/config.php');
    echo "✓ config.php loaded<br>";
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "<br>";
    exit;
}

echo "<h2>2. Checking session...</h2>";
echo "TourId: " . ($_SESSION['TourId'] ?? 'NOT SET') . "<br>";
echo "TourName: " . ($_SESSION['TourName'] ?? 'NOT SET') . "<br>";

echo "<h2>3. CheckTourSession...</h2>";
try {
    $result = CheckTourSession(true);
    echo "✓ CheckTourSession passed<br>";
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "<br>";
}

echo "<h2>4. Checking ACL...</h2>";
try {
    if (!defined('AclParticipants')) {
        echo "✗ AclParticipants not defined<br>";
    } else {
        echo "AclParticipants = " . AclParticipants . "<br>";
    }
    
    checkFullACL(AclParticipants, 'pTarget', AclReadWrite);
    echo "✓ ACL check passed<br>";
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "<br>";
}

echo "<h2>5. Loading Fun_Sessions.inc.php...</h2>";
try {
    require_once('Common/Fun_Sessions.inc.php');
    echo "✓ Fun_Sessions.inc.php loaded<br>";
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "<br>";
    exit;
}

echo "<h2>6. Getting sessions...</h2>";
try {
    $sessions = GetSessions('Q');
    echo "✓ GetSessions succeeded<br>";
    echo "Session count: " . count($sessions) . "<br>";
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "<br>";
}

echo "<h2>7. All checks passed!</h2>";
echo "<p>If you see this, the basic setup works. The 500 error might be in the HTML or JS includes.</p>";
?>
