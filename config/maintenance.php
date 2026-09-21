<?php

/*
|--------------------------------------------------------------------------
| Client maintenance requests
|--------------------------------------------------------------------------
|
| What clients may choose when they create a work order from the portal or
| the apps. Categories must match Propertyware's configured list exactly
| (including its spacing, e.g. "HVAC "); internal ones such as HOA Violation
| or Make ready are deliberately left out.
|
*/

return [

    'categories' => [
        'General Maintenance',
        'Plumbing',
        'Water leak',
        'Electrical',
        'HVAC ',
        'Appliances',
        'Dishwasher',
        'Garbage Disposal',
        'Microwave',
        'Roof',
        'Door',
        'Garage door',
        'Fence',
        'Pest Control',
        'Sprinklers',
    ],

    // Propertyware "source" for portal and app requests; must exist in Propertyware's list.
    'source' => 'Website',

    'type' => 'Service Request',

    // Lets staff find portal-created work orders in Propertyware.
    'search_tag' => 'client-portal',

    'max_photos' => 5,

    // Shown next to "Urgent" so clients call for real emergencies.
    'emergency_phone' => env('MAINTENANCE_EMERGENCY_PHONE'),

];
