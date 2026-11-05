<?php
namespace App\Services;
class SourceCatalog {
 public function groups():array{return [
  'Freelancing platforms'=>['Upwork','Fiverr','Freelancer','PeoplePerHour','Guru','Toptal','Contra','Workana','Truelancer','99designs'],
  'Social media'=>['Facebook','LinkedIn','Instagram','X','Reddit','TikTok','Threads','Pinterest','YouTube','Discord','Telegram','WhatsApp Communities'],
  'Jobs and project posts'=>['Indeed','Glassdoor','Craigslist','SimplyHired','ZipRecruiter','FlexJobs','Remote.co','Wellfound','We Work Remotely','Working Nomads','Remotive','Remote OK','Arbeitnow'],
  'Business directories'=>['Google Maps / Business Profiles','Yelp','Yellow Pages','Yell','Foursquare','BBB','Clutch','GoodFirms','DesignRush','Bark','Trustpilot'],
  'Startup and business communities'=>['Product Hunt','Indie Hackers','Y Combinator','Hacker News','Startup Grind','GrowthHackers','MicroConf','Alignable'],
  'Classifieds and marketplaces'=>['Craigslist','Gumtree','OLX','Locanto','Kijiji','Facebook Marketplace'],
  'Q&A and discussion'=>['Quora','Reddit','Stack Overflow','Stack Exchange','Spiceworks Community','WebmasterWorld'],
  'B2B lead platforms'=>['Apollo.io','ZoomInfo','Lusha','RocketReach','Hunter.io','Snov.io','Seamless.AI','LeadIQ','UpLead','Cognism','Saleshandy','Instantly'],
  'Agency and service marketplaces'=>['DesignRush','Clutch','Sortlist','Bark','Mayple','MarketerHire','Toptal','The Manifest','Agency Spotter'],
  'Events and networking'=>['Eventbrite','Meetup','LinkedIn Events','Bizzabo','Luma','G2 Community','Chamber of Commerce Directories'],
  'Ecommerce and store discovery'=>['Shopify Store Directory','Etsy','Amazon','eBay','WooCommerce Stores','BuiltWith','Store Leads','Wappalyzer'],
  // User-suggested discovery universe. Listing a name does not enable collection.
  'Startup and software directories (research queue)'=>[
   'Product Hunt','BetaList','TrustMRR','Uneed','TinyLaunch','Indie Hackers','Hacker News','Tiny Startup',
   'PeerPush','SideProjectors','DevHunt','Launching Next','Microlaunch','Launch Directories','StartupBase',
   'ShowMeBestAI','Trendy Startups','Software Advice',"There's an AI for that",'AlternativeTo','OpenAlternative',
   'SaaSHub','Toolfolio','LibHunt','SaaS Genius','FoundrList','Stacker News','PitchWall','API List',
   'MakerPad','Dan Recommends','Startup Buffer','AppSumo','SEO Wins','RocketHub','StackSocial',
   'SaaS Mantra','SaaS Warrior','LTD Hunt','KEN Moo','Prime Club','SaaSZilla','Fazier','Peerlist',
   'Next Gen Tools','Sustainability Softwares','Saas Baba','PromptZone','Futurepedia','Toolkitly',
   'LaunchIgniter','Firsto','Indie Tools','Manta','Indie Deals','PayOnceUseForever','Slocco',
   'ToolFame','GPTStore','AlterOpen','SaaS Gallery','Aura Plus Plus','That AI Collection',
   'BasedTools','SaaS Pirate','Product Canyon','Deal Mirror','Dealify','GoodFirms','AI Agent Store',
   'BroUseAI','Altern','BestWebDesignTools','MadGenius','BotsFloor','AIDir Wiki','Look AI Tools',
   'The AI Generation','Waild World','Wavel','Indie Products','Invent List','Hack the Prompt',
   'Startup Heroes','AI Marketing Directory','RankYourAI','EarlyHunt','Tekpon','Dokey AI',
   'Appscribed','Open Tools','SEOFAI','Startups FYI','AI Tool Trek','Powerusers','AI Parabellum',
   'Serchen','RobinGood','Affiliate Watch','IndieHunt','Reviano','Nocode List','Software World',
   'AIxploria','Ctrlalt','AI Hunter','Public APIs',
  ],
 ];}
 public function platforms():array{return array_values(array_unique(array_merge(...array_values($this->groups()))));}
}
