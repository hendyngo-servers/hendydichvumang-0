# HENDY Static Website Source

Only `public/` is published by Render Static Site. It contains public HTML, CSS, JavaScript, SVG, robots and sitemap files.

`telegram/` contains private Bot Admin and Customer UI source copied by the Bot Docker build and served only through authenticated Bot routes. It is deliberately outside the Static Site publish path.

No PHP runtime, PostgreSQL connection, database credentials, payment logic, Bot token or private API key belongs in `public/`.
