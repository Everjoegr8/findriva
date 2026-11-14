<?php

/*
| Word lists behind autocomplete. Plain lists, no AI: suggestions are always optional and the
| person can type anything else.
*/

return [
    'categories' => [
        'Bakery', 'Barbershop', 'Beauty salon', 'Boutique', 'Bridal shop', 'Building materials supplier', 'Car dealer', 'Car wash',
        'Catering service', 'Cleaning service', 'Clinic', 'Clothing store', 'Computer repair', 'Cosmetics store', 'Courier service',
        'Dental clinic', 'Driving school', 'Dry cleaner', 'Electrician', 'Electronics store', 'Event planner', 'Event centre',
        'Fashion designer', 'Fitness centre', 'Florist', 'Food vendor', 'Furniture store', 'Generator repair', 'Gift shop',
        'Grocery store', 'Hair salon', 'Hardware store', 'Hospital', 'Hotel', 'Interior designer', 'Jewellery store', 'Laundry service',
        'Law firm', 'Logistics company', 'Makeup artist', 'Mechanic', 'Mobile phone repair', 'Mobile phone store', 'Nail salon',
        'Optician', 'Painter', 'Pharmacy', 'Photographer', 'Plumber', 'Printing service', 'Real estate agency', 'Restaurant',
        'Shoe store', 'Short-let apartment', 'Solar installer', 'Spa', 'Supermarket', 'Tailor', 'Travel agency', 'Tutoring service',
        'Videographer', 'Wig store', 'Accounting firm', 'Architect', 'Art gallery', 'Bookshop', 'Bar', 'Lounge', 'Café',
        'Day care', 'School', 'Church', 'Mosque', 'Veterinary clinic', 'Pet shop', 'Farm produce supplier', 'Poultry farm',
        'Fish farm', 'Water supplier', 'Gas refill station', 'Security company', 'Recruitment agency', 'Software company',
        'Digital marketing agency', 'Web designer', 'Cake shop', 'Shawarma spot', 'Suya spot', 'Small chops vendor', 'Perfume store',
        'Fabric store', 'Aso-oke weaver', 'Ankara fashion', 'Thrift store', 'Car rental', 'Tyre shop', 'Auto parts store',
    ],

    'cities' => [
        'Lagos', 'Ikeja', 'Lekki', 'Victoria Island', 'Ikoyi', 'Surulere', 'Yaba', 'Ajah', 'Festac', 'Ikorodu', 'Abuja', 'Wuse',
        'Garki', 'Maitama', 'Gwarinpa', 'Kubwa', 'Port Harcourt', 'Ibadan', 'Kano', 'Kaduna', 'Benin City', 'Enugu', 'Onitsha',
        'Aba', 'Owerri', 'Uyo', 'Calabar', 'Abeokuta', 'Ilorin', 'Jos', 'Akure', 'Osogbo', 'Ado-Ekiti', 'Asaba', 'Warri', 'Awka',
        'Abakaliki', 'Umuahia', 'Yenagoa', 'Makurdi', 'Lafia', 'Lokoja', 'Minna', 'Sokoto', 'Katsina', 'Zaria', 'Bauchi', 'Gombe',
        'Yola', 'Jalingo', 'Maiduguri', 'Damaturu', 'Dutse', 'Birnin Kebbi', 'Gusau', 'Ota', 'Sagamu', 'Ijebu-Ode', 'Ogbomosho',
        'Ile-Ife', 'Nnewi', 'Sapele', 'Ughelli', 'Mowe', 'Ibafo',
    ],

    // Common services per category, offered when someone adds services. Keys match the category list.
    'services' => [
        'Bakery' => ['Bread', 'Birthday cakes', 'Wedding cakes', 'Pastries', 'Meat pies', 'Doughnuts'],
        'Barbershop' => ['Haircut', 'Beard trim', 'Shave', 'Hair dye', 'Kids haircut', 'Home service'],
        'Beauty salon' => ['Hair styling', 'Braids', 'Wig installation', 'Manicure', 'Pedicure', 'Facials'],
        'Catering service' => ['Party jollof', 'Small chops', 'Event catering', 'Office lunch', 'Cocktail service'],
        'Cleaning service' => ['Home cleaning', 'Office cleaning', 'Post-construction cleaning', 'Fumigation', 'Upholstery cleaning'],
        'Event planner' => ['Wedding planning', 'Birthday parties', 'Corporate events', 'Decoration', 'Event coordination'],
        'Fashion designer' => ['Bespoke tailoring', 'Bridal wear', 'Aso-ebi styles', 'Alterations', 'Ready-to-wear'],
        'Hair salon' => ['Braids', 'Wig installation', 'Relaxer', 'Hair treatment', 'Locs', 'Weaving'],
        'Laundry service' => ['Wash and fold', 'Dry cleaning', 'Ironing', 'Pick-up and delivery', 'Duvet cleaning'],
        'Makeup artist' => ['Bridal makeup', 'Birthday shoot makeup', 'Gele tying', 'Makeup classes', 'Home service'],
        'Mechanic' => ['Engine repair', 'Servicing', 'Brake repair', 'Car diagnostics', 'AC repair', 'Wheel alignment'],
        'Mobile phone repair' => ['Screen replacement', 'Battery replacement', 'Charging port repair', 'Software repair', 'Water damage repair'],
        'Photographer' => ['Wedding photography', 'Portrait sessions', 'Product photography', 'Event coverage', 'Photo editing'],
        'Plumber' => ['Leak repair', 'Pipe installation', 'Water heater installation', 'Drain unblocking', 'Bathroom fittings'],
        'Restaurant' => ['Dine-in', 'Takeaway', 'Delivery', 'Outdoor catering', 'Private dining'],
        'Solar installer' => ['Solar panel installation', 'Inverter installation', 'Battery replacement', 'Energy audit', 'Maintenance'],
        'Tailor' => ['Bespoke suits', 'Native wear', 'Alterations', 'School uniforms', 'Corporate uniforms'],
        'Pharmacy' => ['Prescriptions', 'Over-the-counter medicines', 'Blood pressure check', 'Home delivery', 'Health advice'],
        'Real estate agency' => ['Property sales', 'Rentals', 'Short-let', 'Property management', 'Land documentation'],
        'Logistics company' => ['Same-day delivery', 'Interstate delivery', 'Haulage', 'Warehousing', 'Dispatch riders'],
    ],
];
