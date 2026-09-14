<?php

return [

    'system' => [
        'validation_failed' => 'The submitted data is invalid.',
        'unauthorized_action' => 'You are not authorized to perform this action.',
        'please_login' => 'Please log in first.',
        'not_found' => 'The requested data was not found.',
        'endpoint_not_found' => 'Endpoint not found.',
        'generic_error' => 'An error occurred.',
        'server_error' => 'A server error occurred.',
        'agent_not_linked' => 'Your account is not yet linked to any agent branch. Contact the Super Admin.',
        'field_required' => 'This field is required.',
        'field_invalid' => 'Invalid value.',
    ],

    'auth' => [
        'login_failed' => 'Incorrect email or password.',
        'register_success' => 'Registration successful.',
        'login_success' => 'Login successful.',
        'logout_success' => 'Logout successful.',
    ],

    'order' => [
        'created' => 'Order created successfully.',
        'status_updated' => 'Order status updated successfully.',
        'cancelled' => 'Order cancelled successfully.',
        'no_agent_branch' => "This konsumen's account is not yet linked to any agent branch.",
        'empty_items' => 'An order must contain at least one item.',
        'agent_profile_incomplete' => "The agent's store profile is incomplete; the order cannot be processed.",
        'invalid_quantity' => 'Invalid item quantity.',
        'insufficient_stock' => 'Insufficient stock for ":item".',
        'invalid_status_transition' => 'Transitioning :entity status from ":from" to ":to" is not allowed.',
        'status_endpoint_required' => 'Use the dedicated endpoint for status ":status".',
        'payment_not_verified' => 'Payment has not been verified yet; the order cannot be processed.',
        'invalid_village' => 'The selected village is invalid.',
    ],

    'product' => [
        'deleted' => 'Product deleted successfully.',
        'image_deleted' => 'Image deleted successfully.',
        'variation_deleted' => 'Variation deleted successfully.',
        'category_deleted' => 'Category deleted successfully.',
        'variation_requires_attribute' => 'A variation must have at least one attribute-value pair.',
        'variation_not_enabled' => 'Product ":name" is not enabled for variations (has_variations = false).',
        'variation_required' => 'Product ":name" requires a variation to be selected.',
        'stock_uses_variation' => 'Product ":name" has variations — stock must be managed per variation, not on the parent product.',
    ],

    'fee' => [
        'updated_product' => 'Product fee updated successfully.',
        'updated_variation' => 'Variation fee updated successfully.',
        'product_uses_variation' => 'This product uses variations — its fee must be read per variation.',
        'variation_uses_product' => 'Product ":name" has variations — set the fee per variation, not on the parent product.',
    ],

    'stock' => [
        'adjusted' => 'Stock adjusted successfully.',
        'agent_id_required' => 'agent_id is required for Super Admin.',
        'invalid_agent' => 'The given agent_id is not a valid Agen account.',
        'negative_result' => 'This adjustment would make the stock negative.',
        'insufficient_column' => 'Invalid stock operation: :column is insufficient.',
    ],

    'user' => [
        'created' => 'Account created successfully.',
        'role_not_authorized' => 'Role ":role" is not authorized to create a ":target" account.',
        'unsupported_role_combination' => 'Unsupported role combination.',
        'agent_id_required_for_role' => 'The agent_id field is required for this role.',
        'invalid_agent' => 'The given agent_id is not a valid Agen account.',
        'invalid_korsal' => "The given korsal_id is not part of this Agen's branch.",
        'deleted' => 'Account deleted successfully.',
        'cannot_delete_super_admin' => 'The Super Admin account cannot be deleted.',
    ],

    'referral' => [
        'not_linked_to_agent' => 'This referral code is not yet linked to any agent branch.',
        'invalid_code' => 'Invalid referral code.',
        'code_not_found' => 'Referral code not found.',
    ],

    'cms' => [
        'block_deleted' => 'Block deleted successfully.',
        'block_activated' => 'Block activated.',
        'block_deactivated' => 'Block deactivated.',
        'reorder_saved' => 'Block order saved successfully.',
        'unknown_type' => 'Unknown block type ":type".',
        'type_rejects_image' => 'Block type ":type" does not accept an image.',
        'article_deleted' => 'Article deleted successfully.',
        'page_deleted' => 'Page deleted successfully.',
    ],

    'payment' => [
        'method_required' => 'A payment method must be selected.',
        'invalid_method' => 'Invalid or inactive payment method.',
        'method_not_configured' => 'Payment method ":name" is not configured yet. Contact the Super Admin.',
        'unsupported_method_type' => 'Unsupported payment method type ":type".',
        'order_not_awaiting_proof' => 'This order does not use bank transfer, or has already been processed.',
        'proof_submitted' => 'Transfer proof submitted, awaiting verification.',
        'verified' => 'Payment verified successfully.',
        'rejected' => 'Payment rejected.',
        'already_verified' => 'This verification has already been processed.',
        'idempotency_key_required' => 'The Idempotency-Key header is required.',
        'order_not_cod' => 'This order is not a Cash on Delivery order.',
        'cod_status_updated' => 'COD payment status updated successfully.',
        'cod_proof_submitted' => 'COD payment proof submitted, awaiting Admin confirmation.',
        'cod_proof_confirmed' => 'COD payment confirmation updated successfully.',
        'cod_proof_already_processed' => 'This COD payment proof has already been processed.',
        'cod_proof_not_found' => 'No COD payment proof has been uploaded for this order yet.',
        'gateway_request_failed' => 'The request to payment gateway ":name" failed. Please try again.',
        'gateway_config_saved' => 'Payment gateway configuration saved successfully.',
        'gateway_toggled' => 'Payment gateway active status updated successfully.',
        'gateway_environment_updated' => 'Payment gateway environment updated successfully.',
        'unsupported_gateway' => 'Payment gateway ":code" is not supported.',
        'invalid_dp_amount' => 'The down payment amount must be greater than 0 and less than the order total.',
        'not_dp_order' => 'This order is not a Down Payment (DP) order.',
        'nothing_to_settle' => 'There is no outstanding balance to settle.',
        'settlement_requested' => 'Settlement request created. The customer must upload the transfer proof.',
        'not_fully_paid' => 'The transaction is not settled yet. Refunds/additional payments can only be processed once the transaction is PAID.',
    ],

    'shipping' => [
        'provider_toggled' => 'Shipping provider active status updated successfully.',
        'provider_config_saved' => 'Shipping provider configuration saved successfully.',
        'unsupported_provider' => 'Shipping provider ":code" is not supported.',
        'method_not_available' => 'The selected shipping method is currently unavailable.',
        'couriers_saved' => 'Courier settings saved successfully.',
        'couriers_save_failed' => 'Failed to save courier settings.',
        'unsupported_couriers' => 'Not supported by the shipping provider: :couriers.',
    ],

    'region' => [
        'imported' => 'Region data imported successfully: :provinces provinces, :regencies regencies, :districts districts, :villages villages.',
        'invalid_csv' => 'Invalid CSV file format.',
        'invalid_row' => 'Row :row is invalid: :reason',
    ],

    'address' => [
        'created' => 'Address saved successfully.',
        'updated' => 'Address updated successfully.',
        'deleted' => 'Address deleted successfully.',
    ],

    'fulfillment' => [
        'invalid_quantity' => 'Invalid fulfillment quantity.',
        'window_closed' => 'Item quantity can only be changed while the order is diproses.',
        'adjusted' => 'Item fulfillment quantity updated successfully.',
        'rescheduled' => 'Item delivery date updated successfully.',
    ],

    'courier' => [
        'invalid_assignment' => 'The selected courier is not in the same agent branch, or is inactive.',
        'no_shipment' => 'This order has no shipment record yet.',
        'no_profile' => 'Your account has no courier profile yet.',
        'not_your_delivery' => 'This order is already assigned to another courier.',
        'assigned' => 'Courier assigned successfully.',
        'return_confirmed' => 'Return status updated successfully.',
        'delivery_proof_required' => 'The kurir must upload delivery proof to mark this shipment as delivered.',
    ],

    'return' => [
        'item_not_delivered' => 'A return can only be requested once the item is terkirim.',
        'invalid_quantity' => 'Return quantity exceeds what can still be returned.',
        'requested' => 'Return request submitted successfully.',
        'reviewed' => 'Return request processed successfully.',
        'already_reviewed' => 'This return request has already been processed.',
        'refund_marked' => 'Refund status updated successfully.',
    ],

    'media' => [
        'unknown_collection' => 'Unknown media collection.',
        'upload_failed' => 'The file failed to upload.',
        'invalid_type' => 'This file type or extension is not allowed.',
        'too_large' => 'The file exceeds the maximum allowed size.',
        'dimensions_too_large' => 'The image dimensions exceed the maximum allowed.',
        'not_found' => 'Media not found.',
        'deleted' => 'Media deleted successfully.',
        'uploaded' => 'Media uploaded successfully.',
        'replaced' => 'Media replaced successfully.',
    ],

    'language' => [
        'cannot_deactivate_default' => 'The default language cannot be deactivated. Pick another default language first.',
        'default_must_be_active' => 'A language must be active before it can be set as default.',
    ],

    'profile' => [
        'current_password_incorrect' => 'Current password is incorrect.',
        'password_updated' => 'Password updated successfully.',
        'referral_code_updated' => 'Referral code updated successfully.',
        'referral_code_format' => 'Referral code may only contain uppercase letters, numbers, and dashes.',
    ],

    'settings' => [
        'updated' => 'Website settings updated successfully.',
    ],

    'agent' => [
        'not_an_agent' => 'The selected user is not an Agen.',
        'profile_already_exists' => 'This agent already has a contact profile.',
        'profile_deleted' => 'Agent contact profile deleted successfully.',
        'profile_updated' => 'Store profile updated successfully.',
    ],

    'referral' => [
        'unsupported_role' => 'Referral reassignment only applies to sales or konsumen.',
        'invalid_target' => 'Invalid reassignment target, or it is outside the same agent branch.',
    ],

    'install' => [
        'connection_success' => 'Database connection succeeded.',
        'connection_failed' => 'Could not connect to the database. Check the host, port, username, and password.',
        'connection_unknown_database' => 'Database not found. Make sure the database name is correct and already created on the server.',
        'database_saved' => 'Database configuration saved successfully.',
        'app_configured' => 'Application configuration saved successfully.',
        'finalized' => 'Finalization complete, application cache has been optimized.',
        'cannot_lock_yet' => 'Cannot lock yet — the Super Admin account hasn\'t been created.',
        'locked' => 'Installation locked successfully. The installer page can no longer be accessed.',

        'already_installed' => 'The application is already installed.',
        'migration_success' => 'Database migration ran successfully.',
        'migrate_first' => 'Run the database migration first.',
        'admin_already_exists' => 'A Super Admin account already exists.',
        'admin_created' => 'Super Admin account created. Installation complete.',
    ],

];
