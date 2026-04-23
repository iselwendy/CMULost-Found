:: should the user be able to edit/delete their reports? for example, nag-report sila ng lost item tapos na-misplace lang pala nila and nakita nila agad after ma-post nung report...?
:: before makapag submit ng lost report yung user is dapat malagay niya muna yung phone number niya and email.
:: can a user claim an item without posting a lost report? in the gallery, there is a claim button
:: purpose of manual lookup vs. purpose of the QR code (if there's a manual lookup, isn't the qr code kinda useless now?)

----------------
TO FIX:
* Only the admin 1 should have the admin accounts available in their settings.php
* My_reports dashboard, QR code not showing after a succesful found report
* Fix the shelves' bin to be a select-type not text-type.
* I just noticed that in matches table in the DB, the resolved/claimed items stays in the matches table even though it was already moved to the archive. 
* As for the matching portal, maybe consider adding "title match"???
* In index.php, improve the search function to allow searching like this (cat sticker + 100 peso bill)
* Create search_filter.php (smart search filter) in user side

