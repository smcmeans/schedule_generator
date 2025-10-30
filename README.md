# schedule_generator

/schedule_generator
        |
        |-- schedule_generator.info.yml     <-- (Required) Tells Drupal your module exists
        |-- schedule_generator.services.yml <-- Defines your "brain" (the Generator Service)
        |-- schedule_generator.routing.yml  <-- Creates the page for the "Generate" button
        |-- schedule_generator.module     <-- (Optional) For hooks, if you need them later
        |
        /src                          <-- (Required) All your PHP classes live here
          |
          |-- ScheduleGeneratorService.php  <-- The "brain": your main logic class
          |
          /Form
            |
            |-- GenerateScheduleForm.php  <-- The "button": the form the student submits
