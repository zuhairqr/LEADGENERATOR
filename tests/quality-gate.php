<?php
require dirname(__DIR__).'/vendor/autoload.php';

use App\Services\LeadQualityGate;

$gate = new LeadQualityGate();
$cases = [
    ['facebook-public','Looking for a Laravel developer','Need a website and Stripe integration.',['Laravel'],null],
    ['instagram-public','Looking for Shopify developer','We need a Shopify checkout integration.',['Shopify'],null],
    ['public-web','[Hiring] Need a WordPress developer','Project budget available for a site redesign.',['Websites'],null],
    ['hackernews','Ask HN: Looking for a WordPress developer to redesign our website','We need a freelancer to redesign our WordPress website.',['Websites'],null],
    ['hackernews','Show HN: Our WordPress website design service','We offer website design.',['Websites'],'SELLER_OR_PROMOTION'],
    ['facebook-public','Help! Am looking for CRM that is easy','Any recommendations please?',['CRM'],'NO_SERVICE_PROJECT_EVIDENCE'],
    ['facebook-public','Looking for the Right CRM Solution for Your Business?','The right implementation makes a difference.',['CRM'],'SELLER_OR_PROMOTION'],
    ['instagram-public','HIRING: Customer Relationship Manager','We are hiring for a position in sales.',['CRM'],'EMPLOYEE_ROLE'],
    ['instagram-public','Looking for the Best CRM Institute','Your IT career starts here.',['CRM'],'TRAINING_OR_CAREER_POST'],
    ['arbeitnow','Performance Marketing Specialist | SEO','Employee benefits, paid leave and salary provided.',['SEO'],'EMPLOYMENT_LISTING_NOT_PROJECT'],
    ['remotive','Full-Stack Developer','A contract remote role with benefits.',['Laravel'],'EMPLOYMENT_LISTING_NOT_PROJECT'],
    ['remotive','Freelance Laravel developer','Build a website with Stripe checkout.',['Laravel'],null],
];
$passed = 0;
foreach ($cases as [$source,$title,$description,$services,$expected]) {
    $actual = $gate->reason($source,$title,$description,$services);
    if ($actual !== $expected) throw new RuntimeException("{$title}: expected ".var_export($expected,true).", got ".var_export($actual,true));
    $passed++;
}
echo "{$passed} quality-gate checks passed.\n";
