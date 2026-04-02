### Yung Terms and Privacy Policy (legal.html) - gawin na lang modal instead of putting them on a separate page


- before makapag submit ng lost report yung user is dapat malagay niya muna yung phone number niya and email.


NEXT STEP:
- Maybe in report_lost.php, add an optional "exact spot left"? Optional there may be times where users remember where they left it or not.
- Fix the profile picture upload in account settings. (Fix account settings)
- CREATE THE MATCHING ENGINE (matches table sa database)
- Improve the search function to allow searching like this (cat sticker + 100 peso bill)
- Improve the duplicateAlert function in report_lost/report_founds
- Fix merging tool

:: should the user be able to edit/delete their reports? for example, nag-report sila ng lost item tapos na-misplace lang pala nila ang nakita nila agad after ma-post nung report...?


!!! MATCHING PORTAL's css styling is too overcrowded, IMPROVE IT
CURRENT issues in matching portaL:
- images of items are not displaying
- only shows items in the review score with confidence score greater than 30%. Therefore, remove the others in the matches table with confidence score less than 30%.
- auto when confidence score is greater than 80%
