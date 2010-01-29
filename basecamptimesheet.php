<?php
/**
 * Script for generating a simple timesheet sorted per milestone from a Basecamp project
 *
 * Henri Bergius <henri.bergius@iki.fi>
 */

if (count($_SERVER['argv']) != 4)
{
    die("basecamptimesheet.php - Generate timesheet from Basecamp data\n\nUsage:\n $ php basecamptimesheet.php hostname apikey projectid\n\n Example: $ php basecamptimesheet.php enormicom xyz1234 123235\n (get API key from account settings, project ID from project URL)\n");
}

$api_url = "https://{$_SERVER['argv'][1]}.basecamphq.com";

if (!function_exists('simplexml_load_string'))
{
    die("PHP simplexml extension is required to run this.\n");
}

// APIkey authentication setup
$stream_context = stream_context_create
(
    array
    (
        'http' => array
        (
            'method'  => 'GET',
            'header'  => "Authorization: Basic " . base64_encode("{$_SERVER['argv'][2]}:X") . "\r\n" .
                         "Content-Type: application/xml\r\n"
        )
    )
);

// Get project information
$data = file_get_contents("{$api_url}/projects/{$_SERVER['argv'][3]}.xml", false, $stream_context);
if (empty($data))
{
    die("Failed to load project data from Basecamp\n");
}
$project = simplexml_load_string($data);


// Get hour reports
$data = file_get_contents("{$api_url}/projects/{$_SERVER['argv'][3]}/time_entries.xml", false, $stream_context);
$time_entries = simplexml_load_string(str_replace('todo-item-id', 'todo_item_id', $data));

// Get milestones
$data = file_get_contents("{$api_url}/projects/{$_SERVER['argv'][3]}/milestones/list", false, $stream_context);
$milestones = simplexml_load_string($data);
$milestones_per_id = array();
foreach ($milestones->milestone as $milestone)
{
    $milestones_per_id[(int) $milestone->id] = $milestone;
}

// Get TODO lists
$data = file_get_contents("{$api_url}/projects/{$_SERVER['argv'][3]}/todo_lists.xml", false, $stream_context);
$todo_lists = simplexml_load_string(str_replace('milestone-id', 'milestone_id', str_replace('todo-list', 'todo_list', $data)));
$todo_lists_per_milestone = array();
foreach ($todo_lists->todo_list as $todo_list)
{
    if (!(int) $todo_list->milestone_id)
    {
        continue;
    }
    
    if (!isset($todo_lists_per_milestone[(int) $todo_list->milestone_id]))
    {
        $todo_lists_per_milestone[(int) $todo_list->milestone_id] = array();
    }
    
    $todo_lists_per_milestone[(int) $todo_list->milestone_id][] = $todo_list->id;
}

$todos_milestones = array();
foreach ($todo_lists_per_milestone as $milestone_id => $todo_lists)
{
    foreach ($todo_lists as $todo_list)
    {
        // Get TODOs
        $data = file_get_contents("{$api_url}/todo_lists/{$todo_list}/todo_items.xml", false, $stream_context);
        $todos = simplexml_load_string(str_replace('todo-item', 'todo_item', $data));
        foreach ($todos->todo_item as $todo_item)
        {
            $todo_milestones[(int) $todo_item->id] = $milestone_id;
        }
    }
}

$time_entries_per_milestone = array();
$time_entries_no_milestone = array();

foreach ($time_entries as $time_entry)
{
    if (   !(int) $time_entry->todo_item_id
        || !isset($todo_milestones[(int) $time_entry->todo_item_id]))
    {
        $time_entries_no_milestone[] = $time_entry;
        continue;
    }
    
    if (!isset($time_entries_per_milestone[$todo_milestones[(int) $time_entry->todo_item_id]]))
    {
        $time_entries_per_milestone[$todo_milestones[(int) $time_entry->todo_item_id]] = array();
    }
    
    $time_entries_per_milestone[$todo_milestones[(int) $time_entry->todo_item_id]][] = $time_entry;
}

echo "Project: {$project->name}\n";
echo "Date: " . date('r') . "\n\n";
$total_hours = 0;

foreach ($time_entries_per_milestone as $milestone => $time_entries)
{
    echo "{$milestones_per_id[$milestone]->title}\n";
    $milestone_hours = 0;
    foreach ($time_entries as $time_entry)
    {
        echo "  {$time_entry->date}: {$time_entry->description} ({$time_entry->hours}h)\n";
        $milestone_hours += (float) $time_entry->hours;
    }
    echo " Total: {$milestone_hours}h\n\n";
    $total_hours += $milestone_hours;
}

echo "No milestone\n";
$milestone_hours = 0;
foreach ($time_entries_no_milestone as $time_entry)
{
    echo "  {$time_entry->date}: {$time_entry->description} ({$time_entry->hours}h)\n";
    $milestone_hours += (float) $time_entry->hours;
}
echo " Total: {$milestone_hours}h\n\n";
$total_hours += $milestone_hours;

echo "Grand total: {$total_hours}\n";
?>
