<?php
namespace App\Services;

/** Screens public listings before they enter the lead inbox. It never grants outreach permission. */
class LeadQualityGate
{
    private const PUBLIC_REQUEST_SOURCES = ['facebook-public', 'instagram-public', 'public-web', 'hackernews'];
    private const CONTRACT_BOARD_SOURCES = ['remotive', 'remoteok', 'arbeitnow', 'jobicy', 'weworkremotely', 'himalayas'];

    public function reason(string $source, string $title, string $description, array $matchedServices): ?string
    {
        if (!in_array($source, [...self::PUBLIC_REQUEST_SOURCES, ...self::CONTRACT_BOARD_SOURCES], true)) return null;
        $text = trim($title.' '.(str_contains($description, "\n\n") ? explode("\n\n", $description, 2)[1] : $description));
        if (!$matchedServices) return 'NO_OFFERED_SERVICE_MATCH';
        if (preg_match('/\b(?:course|training|institute|academy|career|internship|job seeker|open to work)\b/iu', $title)) return 'TRAINING_OR_CAREER_POST';
        $employeeOnly = preg_match('/\b(?:full[- ]time (?:employee|employment|position|role)|permanent (?:employee|position|role)|direct hire only|no (?:agencies|contractors|freelancers)|employees only)\b/iu', $text);
        $externalContract = preg_match('/\b(?:contractors?|freelancers?|agencies|vendors)\b.{0,35}\b(?:welcome|may apply|may submit|accepted|eligible)\b|\b(?:contract|freelance)\s+or\s+full[- ]time\b/iu', $text);
        if ($employeeOnly && !$externalContract) return 'EMPLOYMENT_LISTING_NOT_PROJECT';

        if (in_array($source, self::PUBLIC_REQUEST_SOURCES, true)) {
            if (preg_match('/\b(?:we offer|i offer|our services|my services|hire me|hire us|book a demo|try our|for your business|looking for (?:work|clients|a job)|available for (?:hire|work))\b/iu', $text)) return 'SELLER_OR_PROMOTION';
            if (preg_match('/\b(?:customer relationship manager|account manager|sales manager|marketing specialist|recruiter)\b/iu', $title)) return 'EMPLOYEE_ROLE';
            if (!preg_match('/\b(?:need(?:s|ed)?|looking for|seek(?:s|ing)|hir(?:e|ing)|requir(?:e|es|ed)|wanted|searching for|request for proposals?|rfp)\b/iu', $text)) return 'NO_BUYER_REQUEST';
            $provider = (bool)preg_match('/\b(?:freelanc(?:e|er)|contractor|developer|designer|agency|consultant|expert|someone|team|vendor|qualified firm)\b/iu', $text);
            $deliverable = (bool)preg_match('/\b(?:build|create|develop|design|redesign|rebuild|migrate|integrate|implement|fix|improve|optimi[sz]e|automate|set up|manage)\b.{0,90}\b(?:website|web app|store|shopify|wordpress|woocommerce|ecommerce|e-commerce|seo|crm|chatbot|automation|software|saas|integration|checkout)\b/iu', $text);
            $specificHelp = (bool)preg_match('/\b(?:need|looking for|seeking|require)\b.{0,55}\b(?:website redesign|web development|seo help|seo audit|shopify development|wordpress development|crm implementation)\b/iu', $text);
            if (!$provider && !$deliverable && !$specificHelp) return 'NO_SERVICE_PROJECT_EVIDENCE';
            if (preg_match('/\b(?:recommendations?|which (?:crm|software|platform|app)|best (?:crm|software|platform|app))\b/iu', $text) && !$deliverable && !$specificHelp) return 'PRODUCT_RECOMMENDATION_ONLY';
            return null;
        }

        if (!preg_match('/\b(?:freelance|freelancer|contract|contractor|project)\b/iu', $title)) return 'EMPLOYMENT_LISTING_NOT_PROJECT';
        if (!$externalContract && preg_match('/\b(?:full[- ]time|permanent|employee benefits|paid leave|health insurance|equal opportunity employer)\b/iu', $text)) return 'EMPLOYMENT_LISTING_NOT_PROJECT';
        return null;
    }
}
