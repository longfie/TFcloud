UPDATE `TF_user_identities`
SET `provider` = 'qq_relay', `updated_at` = NOW()
WHERE `provider` = 'qq';
