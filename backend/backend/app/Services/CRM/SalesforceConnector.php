<?php
namespace App\Services\CRM;

class SalesforceConnector implements CRMInterface
{
    public function syncContacts() { return ['synced' => rand(1, 50)]; }
    public function syncLeads() { return ['leads' => rand(1, 20)]; }
    public function createActivity($data) { return ['activity_id' => uniqid()]; }
}
