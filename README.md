# Push Button -> Receive Paper
### (working title was Instazine)
Updated (09-08-20)
Checkout the webpage at https://pbrp.ca/blog for more.

## Disclosure
This code was created primarily with AI using Codex. Only small changes were coded by me by hand.
Overall, I just architect-ed what I knew wanted with the tech knowledge had from CMS systems and microcontrollers.
I had Codex generate the code for me and that allowed me to do the things I needed to do in the time I needed to do it.
I would have loved to have hand-coded this, but it was the difference between using hand-tools and power-tools to make a building go up.
I understood what I asked for and received though, otherwise I would not have used that code.
I was new to making code for ESP32 and Codex was extremely helpful in helping me understand how it differed my usual Arduino and Micropython work.

## What it is
This is intended to be a push-button->receive-paper zine-like super-local publishing project.
It is being prototyped to be served from a localhost CMS in Laravel to a ESP32S3 microcontroller that makes a wifi API GET request and prints the response as a zine-like thing on a thermal printer when a button is pressed.

## What it uses
PHP
Laravel
MySQL
ESP32S3 board                    (more memory is best as PBRP handles picture bitmaps that need space)
TTL/USB thermal printer
JAMMA arcade button              (light up is optional but being used for UX feedback)

## Why?
I maintain a Little Free Library (https://bsky.app/profile/alderlfl.bsky.social or @alderlfl.bsky.social).
One day I found myself wanting to make little zines about my neighbourhood. However, drawing, copying, printing, and cutting everything seemed like a lot of work, so I wound making this. 
Yes, it's more complicated to put together but it allows me to output content in a simple format really fast.

## The Layout
The content decisions are done within the minimalist CMS on the server based on the selected mode and settings parameters.
The ESP32-S3's job is merely to request the content from the server APIs when the button is pressed or a picture is to be printed.
Currently, the settings are all for a 80mm wide paper with 576 pixels over USB. Other support may come later.

In general, the layout follows this format with some adjustments from settings. Mode will determine the content presented.

```
"""""""""""""""""""""""""""""""""" <- Top of sheet
 --------- BANNER IMAGE ----------
 --------- BANNER IMAGE ----------
 Header textline
 ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~ <- decorative divider image
       ARTICLE HEADLINE            <- Double height text
 +--------------------------------+
 |                                |
 |        < Picture area >        |
 |                                |
 +--------------------------------+
 Article text.  Text. Text.
 Article text.  Article text. Text.
 Text. Text.  Article text.
 Article text. Text.
 ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~ <- decorative divider image
 Middle text-only bit.
 ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~ <- decorative divider image
       ARTICLE HEADLINE            <- Double height text
 +--------------------------------+
 |                                |
 |        < Picture area >        |
 |                                |
 +--------------------------------+
 Article text.  Text. Text.
 Article text.  Article text. Text.
 Text. Text.  Article text.
 Article text. Text.
 ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~ <- decorative divider image
 Footer text-only bit.
```

## Content modes
Content mode selection is currently set in the .env file. It can either be:
EXPRESS: Do the requested number of header textlines.
         Randomly select the requested number of alternating articles and middle texts from the settings.
         Do the requested number of footer textlines.

## File systems
More on this later.
For now, understand that the github has 2 independent folders.
The main folder which contains all the needed CMS handling files.
__ESP32 subfolder which contains the the .cpp and related files to flash to the ESP32S3


UPDATES
(09-08-20) Expanded README.md and linked to the start of the website blog.
