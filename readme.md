A XenForo 2 plugin to integration with [Rex Digital Shop](https://shop.rexdigital.group).

# Installation
## XenForo 2 Setup
Upload the files to their apprpriate folders in xenforo you can just drag the 'src' folder into the forum root folder and everything will place itself correctly.
Go to your xenforo admin panel and click on "Add-ons"(In the navigation bar) and then on "Add-ons" again.
Find the Rex Digital Shop plugin and click "Install".

Go to your Xenforo 2 admin panel and click on "Setup"(In the navigation bar) and then on "Options".
Scroll down until you find "Rex Digital Shop Category".

Once there you will need to type in 3 values, which can be found in your rex shop configuration page.
- All you need to do is go to your store on rex digital shop: [Click Here](https://shop.rexdigital.group/merchant)
- Click on the cogwheel at the bottom left.
- Then click on the developer tab in the sidebar.

Now copy & paste your client id, secret & api key to their appropriate settings in your XenForo 2 admin panel.
Make sure you don't share your api key or secret with anyone.

- Next you just need to setup your products on rex digital shop.
- When you're finished go into addons and create an addon called "Usergroup", set the type to "hidden".
    And in the value field you write the id of the usergroup you would like to give to the user once they buy.
- Then attach the addon to all your products which awards that usergroup.
- You can create multiple addons with the same name if you want different producs to give different usergroups.

# Video Tutorial
See the setup process in a short video:
- Coming Soon..

# Webhook URL
To setup the webhook url properly make sure you set the link to the root of your misc.php file in the root of your forum like so:
http://yoursite.com/forum/rexshop-webhook

- Replace "/forum" with where you forum is placed