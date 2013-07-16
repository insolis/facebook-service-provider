facebook-service-provider
=========================

Lighweight Silex service to ease communication with facebook

Doc is to-be-done.

depends:
- request
- url_generator
- session

config:
- fb.options: app_id, app_secret, permissions, redirect_route

session:
- fb.access_token
- fb.page_liked

events:
- fb.user_info
