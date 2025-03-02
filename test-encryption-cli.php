<?php
/**
 * CLI Test script for encryption functionality
 * 
 * This script tests the encryption and decryption of messages
 * to ensure they are properly stored in the database.
 * 
 * Run this script from the command line:
 * php test-encryption-cli.php
 */

// Define constants that our classes expect
define('WP_MESSENGER_CHAT_DIR', __DIR__ . '/');
define('ABSPATH', true); // This is just to bypass the check in the encryption class

// Include the encryption class
require_once 'includes/class-encryption.php';

// Create an instance of the encryption class
$encryption = new WP_Messenger_Chat_Encryption();

// Test message
$test_message = 'This is a test message for encryption: ' . time();
echo "Original message: $test_message\n";

// Encrypt the message
$encrypted_message = $encryption->encrypt($test_message);
echo "Encrypted message: $encrypted_message\n";

// Decrypt the message
$decrypted_message = $encryption->decrypt($encrypted_message);
echo "Decrypted message: $decrypted_message\n";

// Verify that the original message and the decrypted message match
if ($test_message === $decrypted_message) {
    echo "SUCCESS: The original message and the decrypted message match!\n";
} else {
    echo "ERROR: The original message and the decrypted message do not match!\n";
    echo "Original: $test_message\n";
    echo "Decrypted: $decrypted_message\n";
}

echo "\nEncryption test completed.\n";
