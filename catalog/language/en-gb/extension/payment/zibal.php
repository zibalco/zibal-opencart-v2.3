<?php
// Heading
$_['heading_title']             = 'Zibal Online Payment';

// Text
$_['text_title']                = 'Zibal Online Payment';
$_['text_wait']                 = 'Please wait!';
$_['text_connect']              = 'Connecting to the Zibal gateway!';
$_['text_results']              = 'Zibal receipt: ';
$_['text_order_no']             = 'Order No: ';

// Order notes
$_['text_note_paid']            = 'Zibal payment verified.';
$_['text_note_track_id']        = 'Transaction ID (trackId): ';
$_['text_note_paid_at']         = 'Paid at: ';
$_['text_note_card_number']     = 'Card number: ';
$_['text_note_ref_number']      = 'Reference number: ';
$_['text_note_request_failed']  = 'Zibal: creating the transaction failed.';
$_['text_note_verify_failed']   = 'Zibal: transaction failed or cancelled (verify response).';
$_['text_note_inquiry_failed']  = 'Zibal: the follow-up inquiry did not confirm the transaction (inquiry response).';
$_['text_note_mismatch']        = 'Zibal: amount or order ID in the response does not match the stored transaction.';
$_['text_note_http_code']       = 'HTTP code: ';
$_['text_note_response']        = 'Response received:';
$_['text_note_no_response']     = 'No response received. cURL error: ';
$_['text_note_currency']        = 'Zibal: only orders in Rial or Toman can be paid. Order currency code: ';

// Error
$_['error_amount']              = 'The paid amount does not match the transaction amount.';
$_['error_order_id']            = 'Order not found!';
$_['error_verify']              = 'Transaction verification failed.';
$_['error_cant_connect']        = 'There was a problem connecting to the Zibal gateway!<br>'.
                                  'Please try again.<br>'.
                                  'If the problem persists, contact Zibal support.';
$_['error_connect_verify']      = 'There was a problem verifying the payment!<br>'.
                                  'Please try again.<br>'.
                                  'If the problem persists, contact Zibal support.';
$_['error_currency']            = 'The Zibal gateway only works with Rial or Toman.';
$_['error_transaction']         = 'No such transaction is registered for this store.';
$_['error_order_processed']     = 'This order has already been processed and was left unchanged.';
$_['error_status_undefined']    = 'Unknown error';

// Button
$_['button_view_cart']          = 'View Cart';
