<?php
namespace App\Services\CRM;

interface CRMInterface {
    public function syncContacts();
    public function syncLeads();
    public function createActivity($data);
}
