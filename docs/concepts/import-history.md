# Import History

The Import History feature provides complete tracking and auditing of all content imports performed through Compliant Content Publisher.

## Overview

Every import action—whether successful or failed—is logged to the database for future reference. This provides:

- **Audit trail**: Know who imported what and when
- **Troubleshooting**: Identify failed imports and error messages
- **Compliance**: Meet regulatory requirements for content tracking
- **Reporting**: Generate reports on import activity

## Accessing Import History

1. Navigate to **CC Publisher** in WordPress admin
2. Click the **Import History** tab
3. View the table of all import records

## Data Tracked

Each import record includes:

### Basic Information

- **Import ID**: Unique identifier for the import record
- **Timestamp**: Date and time of import (in site's timezone)
- **User**: WordPress user who performed the import
- **Status**: Success or Failed

### Source Information

- **Source URL**: URL of the external WordPress site
- **Source Post ID**: Post ID on the external site
- **Source Post Type**: Type of content (post, page, custom)
- **Post Title**: Title of the imported content

### Destination Information

- **Destination Post ID**: ID of the created post (if successful)
- **Destination Post Link**: Direct link to edit the imported post

### Error Information

- **Error Message**: Detailed error message (if failed)
- **Error Code**: Technical error code for debugging

## Viewing Import Records

### Table Columns

The import history table displays:

| Column | Description |
|--------|-------------|
| Date | When the import occurred |
| User | Who performed the import |
| Source | External site URL |
| Post Title | Title of imported content |
| Status | Success/Failed indicator |
| Destination | Link to imported post |
| Actions | View details, delete record |

### Sorting

Click column headers to sort by:

- Date (default: newest first)
- User
- Source URL
- Status
- Post title

### Filtering

Use filters to narrow down records:

- **Date range**: Show imports within specific dates
- **Status**: Success or Failed only
- **User**: Imports by specific user
- **Source**: Imports from specific external site

### Searching

Use the search box to find records by:

- Post title
- Source URL
- Error message
- User name

## Import Details

Click **View Details** on any record to see:

### Full Import Information

```
Import ID: 123
Timestamp: 2024-10-29 14:23:45
User: admin (ID: 1)
Status: Success

Source:
  URL: https://staging.example.com
  Post ID: 456
  Post Type: post
  Title: "My Example Post"

Destination:
  Post ID: 789
  URL: https://example.com/wp-admin/post.php?post=789&action=edit
  Status: draft

Performance:
  Duration: 8.3 seconds
  Images Imported: 5
  Size: 248 KB
```

### Error Details (for failed imports)

```
Status: Failed
Error Code: media_import_failed
Error Message: Unable to download featured image from source URL
Stack Trace: [detailed technical information]

Attempted Actions:
  1. ✓ Fetched post data
  2. ✓ Validated content
  3. ✓ Transformed content
  4. ✗ Import media (FAILED)
  5. - Create post (SKIPPED)
  6. - Track import (SKIPPED)
```

## Managing Import Records

### Deleting Records

You can delete import history records:

1. Click **Delete** next to a record
2. Confirm the deletion
3. Record removed from history

**Note**: Deleting a history record does NOT delete the imported post.

### Bulk Actions

Select multiple records using checkboxes and:

- **Delete**: Remove selected history records
- **Export**: Download selected records as CSV

### Exporting Data

Export import history for external reporting:

1. Select records (or use "Select All")
2. Choose **Export** from bulk actions
3. Download CSV file

CSV includes all fields for external analysis or archival.

## Data Retention

Import history records are stored indefinitely by default. You can:

### Automatic Cleanup

Add this to your `wp-config.php`:

```php
// Delete import history older than 90 days
define( 'CCP_HISTORY_RETENTION_DAYS', 90 );
```

### Manual Cleanup

Delete old records using date filter:

1. Filter by date range (e.g., "Before 2024-01-01")
2. Select all filtered records
3. Delete via bulk actions

### Database Storage

Import history is stored in a custom database table:

- Table name: `{$wpdb->prefix}ccp_import_history`
- Indexes on: `user_id`, `created_at`, `status`
- Average size: ~500 bytes per record

## Using Import History

### Troubleshooting Failed Imports

1. Filter by **Status: Failed**
2. Review error messages
3. Identify common patterns
4. Address underlying issues

Common failure patterns:

- **Authentication errors**: Check shared secret
- **Media errors**: Verify image URLs
- **Timeout errors**: Reduce content size or increase limits

### Auditing Content Changes

Track what content was imported and when:

1. Filter by source URL
2. Review all imports from that source
3. Export for compliance reporting

### User Activity Monitoring

See who is importing content:

1. Filter by user
2. Review their import history
3. Identify training needs or issues

### Performance Analysis

Analyze import duration and success rates:

1. Export history data to CSV
2. Analyze in spreadsheet:
   - Average import duration
   - Success vs. failure rate
   - Images per import
   - Peak import times

## Integration with Other Logs

Import history complements WordPress' built-in logging:

### Activity Log Plugins

If using an activity log plugin (e.g., WP Activity Log), imports also appear there with actions like:

- "Draft post created via CCP import"
- "Images uploaded via CCP import"

### Error Logs

Server PHP errors related to imports are logged in:

- WordPress debug log (if `WP_DEBUG_LOG` enabled)
- Server error logs

### Combining Logs

For comprehensive troubleshooting:

1. Check CCP Import History for high-level status
2. Check Activity Log for detailed WordPress actions
3. Check PHP error logs for server-level issues

## REST API Access

Developers can access import history programmatically:

```php
// Get import history records
$history = new \CCP\Admin\Import_History();
$records = $history->get_records( [
    'user_id' => get_current_user_id(),
    'status' => 'success',
    'limit' => 50,
] );

// Get a specific record
$record = $history->get_record( 123 );

// Add a custom record (for programmatic imports)
$history->add_record( [
    'source_url' => 'https://staging.example.com',
    'source_post_id' => 456,
    'destination_post_id' => 789,
    'status' => 'success',
    'user_id' => get_current_user_id(),
] );
```

## Privacy Considerations

Import history may contain:

- **Usernames**: Of users who performed imports
- **URLs**: Of source content (may be internal/private)
- **Post titles**: May contain sensitive information

### GDPR Compliance

When a user is deleted:

- Import history records are preserved
- User ID references are maintained
- Username display shows "Deleted User"

### Data Export Requests

Include import history in data export responses:

```php
add_filter( 'wp_privacy_personal_data_exporters', function( $exporters ) {
    $exporters['ccp-import-history'] = [
        'exporter_friendly_name' => 'CCP Import History',
        'callback' => 'ccp_export_user_import_history',
    ];
    return $exporters;
} );
```

## Best Practices

1. **Regular review**: Check import history weekly
2. **Monitor failures**: Investigate failed imports promptly
3. **Clean up old data**: Set retention policy appropriate for your needs
4. **Export for archival**: Backup important import records
5. **Train users**: Show team members how to use import history

## Next Steps

- [Authentication](authentication.md) - Setup secure connections
- [Import Process](import-process.md) - How imports work
- [Troubleshooting](../troubleshooting.md) - Solve common issues
- [Hooks and Filters](../extending/hooks.md) - Extend functionality
