<?php

namespace App;

enum ItemAvailability: string
{
    case NotChecked = 'Not checked';
    case Available = 'Available';
    case PartiallyAvailable = 'Partially available';
    case Unavailable = 'Unavailable';
}
