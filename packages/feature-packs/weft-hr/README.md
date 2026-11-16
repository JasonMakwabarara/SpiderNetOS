# Weft HR

Connects the Jetson attendance app to the SpiderNet employee register, leave, and payroll.

Jetson keeps names, employee numbers, devices, and clock events on the Pi. When the site has internet again it calls `POST /api/enterprise/attendance/sync` with the site secret. Weft HR is the register HR and payroll use. Schema lives in Laravel migrations, not in this manifest.
