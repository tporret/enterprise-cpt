Finalize the `enterprise-cpt.php` entry file. 

1. Implement `register_activation_hook` to:
   - Create the necessary JSON storage directories.
   - Run the initial `Schema.php` check.
2. Initialize the `EnterpriseCPT\API\Field` class so it's globally accessible.
3. Add a `requirements_check()` to ensure the server is running PHP 8.1+ and has the necessary DB privileges to create tables.
4. If requirements aren't met, deactivate the plugin and show a clear admin notice.

Make the code clean, documented, and ready for a production environment.