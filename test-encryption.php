<?php
/**
 * Test script for encryption functionality
 * 
 * This script tests the encryption and decryption of messages
 * to ensure they are properly stored in the database.
 */

// Load WordPress
define('WP_USE_THEMES', false);
require_once('../../../wp-load.php');

// Check if user is logged in and is admin
if (!current_user_can('manage_options')) {
    die('Unauthorized access');
}

echo '<h1>Testing Encryption Functionality</h1>';

// Load required classes
require_once 'includes/class-encryption.php';
require_once 'includes/class-database.php';

// Create instances
$encryption = new WP_Messenger_Chat_Encryption();
$database = new WP_Messenger_Chat_Database();

// Get current user ID
$user_id = get_current_user_id();

// Test message
$test_message = 'This is a test message for encryption: ' . time();
echo '<p>Original message: ' . $test_message . '</p>';

// Manually encrypt the message to see the encrypted value
$encrypted_message = $encryption->encrypt($test_message);
echo '<p>Encrypted message: ' . $encrypted_message . '</p>';

// Manually decrypt the message to verify
$decrypted_message = $encryption->decrypt($encrypted_message);
echo '<p>Manually decrypted message: ' . $decrypted_message . '</p>';

// Check if we have a test conversation
global $wpdb;
$table_conversations = $wpdb->prefix . 'messenger_conversations';
$table_participants = $wpdb->prefix . 'messenger_participants';

// Look for a conversation where the current user is a participant
$conversation_id = $wpdb->get_var($wpdb->prepare(
    "SELECT conversation_id FROM {$table_participants} WHERE user_id = %d LIMIT 1",
    $user_id
));

// If no conversation exists, create one with the current user
if (!$conversation_id) {
    echo '<p>No existing conversation found. Creating a test conversation...</p>';
    
    // Find another user to create a conversation with
    $another_user_id = $wpdb->get_var(
        "SELECT ID FROM {$wpdb->users} WHERE ID != {$user_id} LIMIT 1"
    );
    
    if (!$another_user_id) {
        die('<p>Error: Could not find another user to create a conversation with.</p>');
    }
    
    $conversation_id = $database->create_conversation($user_id, $another_user_id);
    echo '<p>Created conversation ID: ' . $conversation_id . ' between users ' . $user_id . ' and ' . $another_user_id . '</p>';
}

// Save the test message to the database
$message_data = array(
    'conversation_id' => $conversation_id,
    'sender_id' => $user_id,
    'message' => $test_message,
    'sent_at' => current_time('mysql')
);

$message_id = $database->save_message($message_data);

if (!$message_id) {
    die('<p>Error: Failed to save message to database.</p>');
}

echo '<p>Saved message to database with ID: ' . $message_id . '</p>';

// Retrieve the message directly from the database to see the encrypted value
$table_messages = $wpdb->prefix . 'messenger_messages';
$raw_message = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$table_messages} WHERE id = %d",
    $message_id
));

echo '<p>Raw message from database (should be encrypted): ' . $raw_message->message . '</p>';

// Now retrieve the message using the database class (which should decrypt it)
$messages = $database->get_messages($conversation_id, $user_id);

if (!$messages) {
    die('<p>Error: Failed to retrieve messages.</p>');
}

// Find our test message
$retrieved_message = null;
foreach ($messages as $message) {
    if ($message->id == $message_id) {
        $retrieved_message = $message;
        break;
    }
}

if (!$retrieved_message) {
    die('<p>Error: Could not find the test message in the retrieved messages.</p>');
}

echo '<p>Retrieved message from database (should be decrypted): ' . $retrieved_message->message . '</p>';

// Verify that the original message and the retrieved message match
if ($test_message === $retrieved_message->message) {
    echo '<p style="color: green; font-weight: bold;">SUCCESS: The original message and the retrieved message match!</p>';
} else {
    echo '<p style="color: red; font-weight: bold;">ERROR: The original message and the retrieved message do not match!</p>';
    echo '<p>Original: ' . $test_message . '</p>';
    echo '<p>Retrieved: ' . $retrieved_message->message . '</p>';
}

echo '<h2>Conclusion</h2>';
echo '<p>If you see "SUCCESS" above, the encryption and decryption are working correctly.</p>';
echo '<p>The message is encrypted in the database but appears decrypted when retrieved through the application.</p>';
