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

Schedule Generation  
Currently, the generation algorithm goes through classes and adds each class that the student has all the prereqs for  
This could lead to potential issues when an important class is not taken, leading to the majority of classes being locked down  
This could be remedied 2 ways:  
- Find classes with most prerequisites and prioritize them
- Use random sampling to generate the schedule with the closest desired credit hours
