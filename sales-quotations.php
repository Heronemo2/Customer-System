<?php
// Quotations needing a decision are now a filter on My Orders / Quotations.
header('Location: quotations.php?filter=pending');
exit;
