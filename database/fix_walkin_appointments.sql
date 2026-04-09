-- Fix walk-in appointments by allowing NULL user_id
-- This allows the system to create walk-in appointments without a registered user

-- Drop the existing foreign key constraint
ALTER TABLE appointments 
DROP FOREIGN KEY appointments_ibfk_1;

-- Modify user_id column to allow NULL
ALTER TABLE appointments 
MODIFY user_id INT NULL;

-- Re-add the foreign key constraint with NULL support
ALTER TABLE appointments 
ADD CONSTRAINT appointments_ibfk_1 
FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;
